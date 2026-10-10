<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Backup\BackupEncryption;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupEncryptionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'novanuke-backup-encryption-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
        parent::tearDown();
    }

    public function testRoundTripUsesBoundedChunksAndPreservesLargeArtifact(): void
    {
        $source = $this->write('source.bin', str_repeat('NovaNuke-', 400000));
        $encrypted = $this->directory . '/source.nnb';
        $crypt = new BackupEncryption();

        $metadata = $crypt->encrypt($source, $encrypted, 'operator-passphrase');
        $decrypted = $crypt->decryptToTemp($encrypted, $this->directory, 'operator-passphrase');

        self::assertSame(filesize($source), $metadata['bytes']);
        self::assertSame(hash_file('sha256', $source), $metadata['sha256']);
        self::assertSame(file_get_contents($source), file_get_contents($decrypted['path']));
        self::assertSame(BackupEncryption::CHUNK_BYTES, $decrypted['metadata']['chunk_bytes']);
    }

    public function testExactChunkBoundaryGetsAnAuthenticatedFinalChunk(): void
    {
        $source = $this->write('boundary.bin', str_repeat('x', BackupEncryption::CHUNK_BYTES));
        $encrypted = $this->directory . '/boundary.nnb';
        $crypt = new BackupEncryption();
        $crypt->encrypt($source, $encrypted, 'operator-passphrase');

        $decrypted = $crypt->decryptToTemp($encrypted, $this->directory, 'operator-passphrase');
        self::assertSame(file_get_contents($source), file_get_contents($decrypted['path']));
    }

    public function testEachEncryptionUsesAUniqueSaltAndCiphertext(): void
    {
        $source = $this->write('same.bin', 'same backup content');
        $first = $this->directory . '/first.nnb';
        $second = $this->directory . '/second.nnb';
        $crypt = new BackupEncryption();

        $one = $crypt->encrypt($source, $first, 'operator-passphrase');
        $two = $crypt->encrypt($source, $second, 'operator-passphrase');

        self::assertNotSame($one['salt'], $two['salt']);
        self::assertNotSame(hash_file('sha256', $first), hash_file('sha256', $second));
    }

    public function testWrongPassphraseFailsClosedAndLeavesNoPlaintext(): void
    {
        $source = $this->write('secret.bin', 'sensitive backup bytes');
        $encrypted = $this->directory . '/secret.nnb';
        $crypt = new BackupEncryption();
        $crypt->encrypt($source, $encrypted, 'correct-passphrase');

        try {
            $crypt->decryptToTemp($encrypted, $this->directory, 'wrong-passphrase');
            self::fail('Wrong passphrase unexpectedly decrypted the artifact.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('authentication failed', $error->getMessage());
            self::assertStringNotContainsString('wrong-passphrase', $error->getMessage());
        } finally {
            self::assertSame([], glob($this->directory . '/.decrypted-*') ?: []);
        }
    }

    public function testCiphertextCorruptionAndTruncationFailAuthentication(): void
    {
        $source = $this->write('corrupt.bin', str_repeat('corruption-check', 10000));
        $crypt = new BackupEncryption();
        $encrypted = $this->directory . '/corrupt.nnb';
        $crypt->encrypt($source, $encrypted, 'correct-passphrase');
        $bytes = file_get_contents($encrypted);
        self::assertIsString($bytes);
        $bytes[strlen($bytes) - 10] = $bytes[strlen($bytes) - 10] ^ "\x01";
        file_put_contents($encrypted, $bytes);
        try {
            $crypt->decryptToTemp($encrypted, $this->directory, 'correct-passphrase');
            self::fail('Corrupted ciphertext unexpectedly decrypted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('authentication failed', $error->getMessage());
            self::assertStringNotContainsString('correct-passphrase', $error->getMessage());
        }
    }

    public function testTruncatedEnvelopeFailsClosed(): void
    {
        $source = $this->write('truncated.bin', str_repeat('truncate-check', 10000));
        $encrypted = $this->directory . '/truncated.nnb';
        $crypt = new BackupEncryption();
        $crypt->encrypt($source, $encrypted, 'correct-passphrase');
        $contents = file_get_contents($encrypted);
        self::assertIsString($contents);
        file_put_contents($encrypted, substr($contents, 0, -1));

        try {
            $crypt->decryptToTemp($encrypted, $this->directory, 'correct-passphrase');
            self::fail('Truncated ciphertext unexpectedly decrypted.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('correct-passphrase', $error->getMessage());
        }
    }

    public function testUnsupportedEnvelopeVersionAndMissingSecretAreRejected(): void
    {
        $source = $this->write('version.bin', 'versioned backup');
        $encrypted = $this->directory . '/version.nnb';
        $crypt = new BackupEncryption();
        $crypt->encrypt($source, $encrypted, 'correct-passphrase');
        $contents = file_get_contents($encrypted);
        self::assertIsString($contents);
        $parts = explode("\n", $contents, 3);
        $metadata = json_decode($parts[1], true, 16, JSON_THROW_ON_ERROR);
        $metadata['format'] = 999;
        $parts[1] = json_encode($metadata, JSON_THROW_ON_ERROR);
        file_put_contents($encrypted, implode("\n", $parts));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported');
        $crypt->decryptToTemp($encrypted, $this->directory, 'correct-passphrase');
    }

    public function testMissingPassphraseIsRejectedBeforeReadingCiphertext(): void
    {
        $source = $this->write('missing-secret.bin', 'secret');
        $encrypted = $this->directory . '/missing-secret.nnb';
        $crypt = new BackupEncryption();
        $crypt->encrypt($source, $encrypted, 'correct-passphrase');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('passphrase is required');
        $crypt->decryptToTemp($encrypted, $this->directory, '');
    }

    public function testPlaintextArtifactsRemainOutsideTheEncryptedEnvelopeContract(): void
    {
        $source = $this->write('legacy.sql', "-- NovaNuke database backup\n");
        self::assertFalse(BackupEncryption::isEncryptedFile($source));
        self::assertSame('legacy.sql', basename($source));
    }

    private function write(string $name, string $content): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        self::assertSame(strlen($content), file_put_contents($path, $content));
        @chmod($path, 0600);
        return $path;
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($root);
    }
}
