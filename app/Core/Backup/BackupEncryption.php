<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use RuntimeException;
use Throwable;

/**
 * Streaming authenticated envelope for newly-created backup artifacts.
 * The passphrase is never serialized; only the KDF salt and public envelope
 * parameters are stored beside the ciphertext.
 */
final class BackupEncryption
{
    public const FORMAT = 1;
    public const CHUNK_BYTES = 1_048_576;
    private const MAGIC = "NOVANUKE-BACKUP-ENCRYPTED\n";
    private const SALT_BYTES = SODIUM_CRYPTO_PWHASH_SALTBYTES;

    public function __construct()
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('The sodium extension is required for encrypted backups.');
        }
    }

    /** @return array{format:int,algorithm:string,kdf:string,salt:string,chunk_bytes:int,original_name:string,bytes:int,sha256:string} */
    public function encrypt(string $source, string $destination, string $passphrase): array
    {
        $this->assertPassphrase($passphrase);
        $this->assertRegularFile($source);
        $this->assertDestination($destination);

        $sourceBytes = filesize($source);
        $sourceSha256 = hash_file('sha256', $source);
        if ($sourceBytes === false || $sourceSha256 === false) throw new RuntimeException('Unable to fingerprint the backup artifact.');

        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Unable to open the backup encryption streams.');
        }

        @chmod($destination, 0600);
        $salt = random_bytes(self::SALT_BYTES);
        $key = '';
        $state = null;
        $bytes = 0;
        $hash = hash_init('sha256');
        $metadata = [
            'format' => self::FORMAT,
            'algorithm' => 'xchacha20poly1305-secretstream',
            'kdf' => 'argon2id13',
            'salt' => base64_encode($salt),
            'chunk_bytes' => self::CHUNK_BYTES,
            'original_name' => basename($source),
            'bytes' => $sourceBytes,
            'sha256' => $sourceSha256,
        ];

        try {
            $key = $this->deriveKey($passphrase, $salt);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $line = self::MAGIC . json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (fwrite($output, $line) !== strlen($line) || fwrite($output, $header) !== strlen($header)) {
                throw new RuntimeException('Unable to write the encrypted backup envelope.');
            }

            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK_BYTES);
                if ($chunk === false) throw new RuntimeException('Unable to read the backup artifact.');
                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
                $tag = feof($input) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                $length = pack('N', strlen($ciphertext) | ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL ? 0x80000000 : 0));
                if (fwrite($output, $length) !== 4 || fwrite($output, $ciphertext) !== strlen($ciphertext)) {
                    throw new RuntimeException('Unable to write the encrypted backup chunk.');
                }
            }

            if (! fflush($output)) throw new RuntimeException('Unable to flush the encrypted backup envelope.');
            if ($bytes !== $sourceBytes || ! hash_equals($sourceSha256, hash_final($hash))) throw new RuntimeException('Backup artifact changed while it was encrypted.');
            return $metadata;
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
            if ($key !== '') sodium_memzero($key);
        }
    }

    /** @return array{path:string,metadata:array<string,mixed>} */
    public function decryptToTemp(string $source, string $temporaryDirectory, string $passphrase): array
    {
        $this->assertPassphrase($passphrase);
        $this->assertRegularFile($source);
        if ($temporaryDirectory === '' || is_link($temporaryDirectory)) throw new RuntimeException('Encrypted backup temporary directory is unsafe.');
        if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
            throw new RuntimeException('Unable to create the encrypted backup temporary directory.');
        }
        $destination = rtrim($temporaryDirectory, '/\\') . DIRECTORY_SEPARATOR . '.decrypted-' . bin2hex(random_bytes(8));
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($destination);
            throw new RuntimeException('Unable to open the encrypted backup streams.');
        }
        try {
            $metadata = $this->readMetadata($input);
            $key = $this->deriveKey($passphrase, base64_decode((string) $metadata['salt'], true));
            $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (! is_string($header) || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Encrypted backup envelope is truncated.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $bytes = 0;
            while (true) {
                $length = fread($input, 4);
                if ($length === '' || $length === false) throw new RuntimeException('Encrypted backup envelope is truncated.');
                if (strlen($length) !== 4) throw new RuntimeException('Encrypted backup chunk length is truncated.');
                $encodedSize = unpack('Nsize', $length)['size'];
                $final = ($encodedSize & 0x80000000) !== 0;
                $size = $encodedSize & 0x7fffffff;
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Encrypted backup chunk size is invalid.');
                }
                $ciphertext = fread($input, $size);
                if (! is_string($ciphertext) || strlen($ciphertext) !== $size) throw new RuntimeException('Encrypted backup ciphertext is truncated.');
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext, '');
                if ($pulled === false || ! is_array($pulled) || ! isset($pulled[0], $pulled[1])) throw new RuntimeException('Encrypted backup authentication failed.');
                [$plain, $tag] = $pulled;
                if (! is_string($plain)) throw new RuntimeException('Encrypted backup plaintext is malformed.');
                $actualFinal = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                if ($actualFinal !== $final) throw new RuntimeException('Encrypted backup final-chunk marker is invalid.');
                if (fwrite($output, $plain) !== strlen($plain)) throw new RuntimeException('Unable to write the decrypted backup artifact.');
                $bytes += strlen($plain);
                if ($final) break;
            }
            if (fread($input, 1) !== '') throw new RuntimeException('Encrypted backup contains trailing data.');
            if ($bytes !== (int) $metadata['bytes'] || ! hash_equals((string) $metadata['sha256'], hash_file('sha256', $destination) ?: '')) {
                throw new RuntimeException('Decrypted backup checksum does not match the envelope.');
            }
            fflush($output);
            @chmod($destination, 0600);
            return ['path' => $destination, 'metadata' => $metadata];
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
            if (isset($key)) sodium_memzero($key);
        }
    }

    public static function isEncryptedFile(string $path): bool
    {
        if (! is_file($path) || is_link($path)) return false;
        $handle = fopen($path, 'rb');
        if ($handle === false) return false;
        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);
        return $magic === self::MAGIC;
    }

    public function isEncrypted(string $path): bool
    {
        return self::isEncryptedFile($path);
    }

    /** @return array<string,mixed> */
    public function metadata(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Encrypted backup is not readable.');
        try { return $this->readMetadata($handle); } finally { fclose($handle); }
    }

    private function readMetadata($input): array
    {
        $magic = fread($input, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) throw new RuntimeException('Encrypted backup envelope magic is invalid.');
        $line = fgets($input, 16 * 1024);
        if (! is_string($line) || ! str_ends_with($line, "\n")) throw new RuntimeException('Encrypted backup envelope metadata is truncated.');
        $metadata = json_decode(trim($line), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($metadata) || ($metadata['format'] ?? null) !== self::FORMAT || ($metadata['algorithm'] ?? null) !== 'xchacha20poly1305-secretstream' || ($metadata['kdf'] ?? null) !== 'argon2id13' || ! is_int($metadata['chunk_bytes'] ?? null) || $metadata['chunk_bytes'] !== self::CHUNK_BYTES || ! is_string($metadata['salt'] ?? null) || ! is_string($metadata['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $metadata['sha256']) || ! is_int($metadata['bytes'] ?? null) || $metadata['bytes'] < 0) {
            throw new RuntimeException('Encrypted backup envelope metadata is unsupported or malformed.');
        }
        $salt = base64_decode($metadata['salt'], true);
        if ($salt === false || strlen($salt) !== self::SALT_BYTES) throw new RuntimeException('Encrypted backup envelope salt is invalid.');
        return $metadata;
    }

    private function deriveKey(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private function assertPassphrase(string $passphrase): void
    {
        if ($passphrase === '' || strlen($passphrase) > 4096) throw new RuntimeException('An encryption passphrase is required and must be no longer than 4096 bytes.');
    }

    private function assertRegularFile(string $path): void
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) throw new RuntimeException('Backup artifact is not a readable regular file.');
    }

    private function assertDestination(string $path): void
    {
        if ($path === '' || is_link(dirname($path)) || file_exists($path)) throw new RuntimeException('Encrypted backup destination is unsafe.');
        $parent = dirname($path);
        if (! is_dir($parent) || ! is_writable($parent)) throw new RuntimeException('Encrypted backup destination directory is unavailable.');
    }
}
