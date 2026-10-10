<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use RuntimeException;
use Throwable;

final class BackupExportBundle
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly string $backupDirectory)
    {
    }

    /** @return array{path:string,backup_set_id:string,encrypted:bool,verification:string} */
    public function create(string $backupSetId, string $destination): array
    {
        $setId = BackupSetId::normalize($backupSetId);
        $sourceDirectory = $this->sourceDirectory($setId);
        $manifestPath = $sourceDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $verified = (new BackupVerifier($this->backupDirectory))->verifyManifestForExport($manifestPath);
        $manifest = $verified['manifest'];
        $components = $manifest['components'];
        $manifestText = file_get_contents($manifestPath);
        if (! is_string($manifestText) || $this->containsPrivatePath(json_decode($manifestText, true, 32, JSON_THROW_ON_ERROR))) throw new RuntimeException('Backup manifest contains a private server path.');
        $this->assertDestination($destination);
        $parent = dirname($destination);
        $this->assertNoSymlinkAncestor($parent);
        if (! is_dir($parent) && (! mkdir($parent, 0700, true) || ! is_dir($parent))) throw new RuntimeException('Export storage is unavailable.');
        if (! is_writable($parent)) throw new RuntimeException('Export storage is unavailable.');

        $temporary = $destination . '.part-' . bin2hex(random_bytes(8));
        $encrypted = (($components['database']['encrypted'] ?? false) === true) || (($components['files']['encrypted'] ?? false) === true);
        $verification = $encrypted ? 'manifest-and-ciphertext-verified; passphrase-required-for-aead-verification' : 'manifest-and-artifacts-verified';
        $descriptor = [
            'schema_version' => self::SCHEMA_VERSION,
            'format' => 'novanuke-portable-backup-tar',
            'backup_set_id' => $setId,
            'created_at' => gmdate(DATE_ATOM),
            'core_version' => (string) ($manifest['cms_version'] ?? 'unknown'),
            'verification' => $verification,
            'encryption' => $encrypted ? 'preserved-ciphertext' : 'plaintext',
            'files' => [
                ['path' => 'manifest.json', 'bytes' => filesize($manifestPath), 'sha256' => hash_file('sha256', $manifestPath), 'kind' => 'manifest'],
                ['path' => 'database/' . $this->safeName($components['database']['name']), 'bytes' => (int) $components['database']['bytes'], 'sha256' => (string) $components['database']['sha256'], 'kind' => 'database'],
                ['path' => 'files/' . $this->safeName($components['files']['name']), 'bytes' => (int) $components['files']['bytes'], 'sha256' => (string) $components['files']['sha256'], 'kind' => 'files'],
            ],
            'warnings' => $encrypted ? ['Ciphertext is preserved; authenticated decryption still requires the original external passphrase.'] : ['This export contains sensitive plaintext backup data.'],
        ];
        if (! is_int($descriptor['files'][0]['bytes']) || ! is_string($descriptor['files'][0]['sha256'])) throw new RuntimeException('Export manifest identity is unavailable.');
        $stream = $this->openPrivateFile($temporary, 'Unable to create the export bundle.');
        try {
            chmod($temporary, 0600);
            $writer = new TarWriter($stream);
            $writer->addString('export.json', json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $writer->addFile('manifest.json', $manifestPath);
            $writer->addFile('database/' . $this->safeName($components['database']['name']), $sourceDirectory . DIRECTORY_SEPARATOR . $this->safeName($components['database']['name']));
            $writer->addFile('files/' . $this->safeName($components['files']['name']), $sourceDirectory . DIRECTORY_SEPARATOR . $this->safeName($components['files']['name']));
            $writer->finish();
            if (! fflush($stream)) throw new RuntimeException('Unable to finalize the export bundle.');
            fclose($stream);
            $stream = null;
            $this->verify($temporary);
            if (! rename($temporary, $destination)) throw new RuntimeException('Unable to publish the export bundle.');
            chmod($destination, 0600);
            return ['path' => $destination, 'backup_set_id' => $setId, 'encrypted' => $encrypted, 'verification' => $verification];
        } catch (Throwable $error) {
            if (is_resource($stream)) fclose($stream);
            @unlink($temporary);
            @unlink($destination);
            throw $error;
        }
    }

    /** @return array{backup_set_id:string,encrypted:bool,verification:string,manifest:array<string,mixed>} */
    public function verify(string $bundlePath): array
    {
        if (! is_file($bundlePath) || is_link($bundlePath) || ! is_readable($bundlePath)) throw new RuntimeException('Export bundle is unavailable.');
        $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'novanuke-export-verify-' . bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0700) || ! is_dir($temporary)) throw new RuntimeException('Export verification storage is unavailable.');
        try {
            $entries = $this->readTar($bundlePath, $temporary);
            $descriptor = $this->jsonEntry($entries, 'export.json', 2 * 1024 * 1024);
            $this->validateDescriptor($descriptor, $entries);
            $setId = BackupSetId::normalize((string) $descriptor['backup_set_id']);
            $setDirectory = $temporary . DIRECTORY_SEPARATOR . $setId;
            if (! mkdir($setDirectory, 0700)) throw new RuntimeException('Export verification storage is unavailable.');
            $stagedManifest = $setDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
            if (! copy($entries['manifest.json']['path'], $stagedManifest) || ! chmod($stagedManifest, 0600)) throw new RuntimeException('Export manifest could not be staged.');
            foreach (['database', 'files'] as $kind) {
                $relative = (string) $descriptor['files'][$kind === 'database' ? 1 : 2]['path'];
                $name = $this->safeName(basename($relative));
                $stagedArtifact = $setDirectory . DIRECTORY_SEPARATOR . $name;
                if (! copy($entries[$relative]['path'], $stagedArtifact) || ! chmod($stagedArtifact, 0600)) throw new RuntimeException('Export artifact could not be staged.');
            }
            $verified = (new BackupVerifier($temporary))->verifyManifestForExport($setDirectory . DIRECTORY_SEPARATOR . 'manifest.json');
            return ['backup_set_id' => $setId, 'encrypted' => $descriptor['encryption'] === 'preserved-ciphertext', 'verification' => (string) $descriptor['verification'], 'manifest' => $verified['manifest']];
        } finally {
            $this->removeTree($temporary);
        }
    }

    /** @return array<string,array{path:string,bytes:int,sha256:string}> */
    private function readTar(string $path, string $temporary): array
    {
        $input = fopen($path, 'rb');
        if ($input === false) throw new RuntimeException('Export bundle is not readable.');
        $entries = [];
        $terminated = false;
        try {
            while (($header = fread($input, 512)) !== false && $header !== '') {
                if (strlen($header) !== 512) throw new RuntimeException('Export bundle TAR header is truncated.');
                if ($header === str_repeat("\0", 512)) {
                    if (fread($input, 512) !== str_repeat("\0", 512) || fread($input, 1) !== '') throw new RuntimeException('Export bundle TAR terminator is invalid.');
                    $terminated = true;
                    break;
                }
                $pathName = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $archivePath = $prefix === '' ? $pathName : $prefix . '/' . $pathName;
                $this->assertArchivePath($archivePath);
                if (isset($entries[$archivePath]) || substr($header, 156, 1) !== '0' || substr($header, 257, 6) !== "ustar\0" || substr($header, 263, 2) !== '00' || ! $this->validTarChecksum($header)) throw new RuntimeException('Export bundle contains an invalid or duplicate entry.');
                $sizeField = trim(substr($header, 124, 12), "\0 ");
                if ($sizeField === '' || preg_match('/^[0-7]+$/', $sizeField) !== 1) throw new RuntimeException('Export bundle TAR size is invalid.');
                $size = octdec($sizeField);
                $target = $temporary . DIRECTORY_SEPARATOR . 'entry-' . count($entries);
                $output = $this->openPrivateFile($target, 'Export verification storage is unavailable.');
                $hash = hash_init('sha256');
                $remaining = $size;
                try {
                    while ($remaining > 0) {
                        $chunk = fread($input, min(1048576, $remaining));
                        if (! is_string($chunk) || $chunk === '') throw new RuntimeException('Export bundle entry is truncated.');
                        if (fwrite($output, $chunk) !== strlen($chunk)) throw new RuntimeException('Export verification storage is unavailable.');
                        hash_update($hash, $chunk);
                        $remaining -= strlen($chunk);
                    }
                } finally { fclose($output); }
                $padding = (512 - ($size % 512)) % 512;
                if ($padding > 0 && fread($input, $padding) !== str_repeat("\0", $padding)) throw new RuntimeException('Export bundle TAR padding is invalid.');
                $entries[$archivePath] = ['path' => $target, 'bytes' => $size, 'sha256' => hash_final($hash)];
                if (count($entries) > 4) throw new RuntimeException('Export bundle contains too many entries.');
            }
        } finally { fclose($input); }
        if (! $terminated || count($entries) !== 4) throw new RuntimeException('Export bundle is incomplete.');
        return $entries;
    }

    /** @param array<string,array{path:string,bytes:int,sha256:string}> $entries @return array<string,mixed> */
    private function jsonEntry(array $entries, string $name, int $maxBytes): array
    {
        if (! isset($entries[$name]) || $entries[$name]['bytes'] > $maxBytes) throw new RuntimeException('Export descriptor is unavailable.');
        $value = json_decode((string) file_get_contents($entries[$name]['path']), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value)) throw new RuntimeException('Export descriptor is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $descriptor @param array<string,array{path:string,bytes:int,sha256:string}> $entries */
    private function validateDescriptor(array $descriptor, array $entries): void
    {
        foreach (['schema_version', 'format', 'backup_set_id', 'created_at', 'core_version', 'verification', 'encryption', 'files', 'warnings'] as $key) if (! array_key_exists($key, $descriptor)) throw new RuntimeException('Export descriptor is incomplete.');
        if (($descriptor['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($descriptor['format'] ?? null) !== 'novanuke-portable-backup-tar' || ! is_string($descriptor['backup_set_id'] ?? null)
            || ! is_string($descriptor['created_at'] ?? null) || strtotime($descriptor['created_at']) === false || ! is_string($descriptor['core_version'] ?? null)
            || ! in_array($descriptor['verification'] ?? null, ['manifest-and-artifacts-verified', 'manifest-and-ciphertext-verified; passphrase-required-for-aead-verification'], true)
            || ! in_array($descriptor['encryption'] ?? null, ['plaintext', 'preserved-ciphertext'], true) || ! is_array($descriptor['files'] ?? null) || count($descriptor['files']) !== 3 || ! is_array($descriptor['warnings']) || ! array_is_list($descriptor['warnings'])) throw new RuntimeException('Export descriptor is unsupported.');
        if (! array_is_list($descriptor['files'])) throw new RuntimeException('Export descriptor file inventory is invalid.');
        BackupSetId::normalize($descriptor['backup_set_id']);
        foreach ($descriptor['files'] as $index => $file) {
            $expectedKind = ['manifest', 'database', 'files'][$index] ?? '';
            if (! is_array($file) || ! is_string($file['path'] ?? null) || ! is_int($file['bytes'] ?? null) || $file['bytes'] < 0 || ! is_string($file['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $file['sha256']) !== 1 || ($file['kind'] ?? null) !== $expectedKind || ! isset($entries[$file['path']])) throw new RuntimeException('Export descriptor file inventory is invalid.');
            $this->assertArchivePath($file['path']);
            if (($index === 0 && $file['path'] !== 'manifest.json') || ($index === 1 && ! str_starts_with($file['path'], 'database/')) || ($index === 2 && ! str_starts_with($file['path'], 'files/'))) throw new RuntimeException('Export descriptor file inventory is invalid.');
            if ($entries[$file['path']]['bytes'] !== $file['bytes'] || ! hash_equals($entries[$file['path']]['sha256'], $file['sha256'])) throw new RuntimeException('Export descriptor file inventory does not match the bundle.');
        }
        foreach (['manifest.json', 'database/' . basename((string) $descriptor['files'][1]['path']), 'files/' . basename((string) $descriptor['files'][2]['path'])] as $path) if (! isset($entries[$path])) throw new RuntimeException('Export bundle is missing a required file.');
    }

    private function sourceDirectory(string $setId): string
    {
        if (! is_dir($this->backupDirectory) || is_link($this->backupDirectory)) throw new RuntimeException('Backup storage is unavailable.');
        $directory = rtrim($this->backupDirectory, '/\\') . DIRECTORY_SEPARATOR . $setId;
        if (! is_dir($directory) || is_link($directory)) throw new RuntimeException('Backup set not found.');
        return $directory;
    }

    private function safeName(string $name): string
    {
        if ($name === '' || $name !== basename(str_replace('\\', '/', $name)) || str_contains($name, '/') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) throw new RuntimeException('Backup artifact filename is unsafe.');
        return $name;
    }

    private function assertDestination(string $path): void
    {
        if ($path === '' || is_link($path) || file_exists($path) || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || is_link(dirname($path)) || basename($path) === '' || basename($path) === '.' || basename($path) === '..' || str_contains(basename($path), '\\') || str_contains(basename($path), '/')) throw new RuntimeException('Export destination is unsafe.');
    }

    private function assertArchivePath(string $path): void
    {
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || preg_match('#(^|/)\.\.(?:/|$)#', $path) === 1 || preg_match('/^[A-Za-z]:/', $path) === 1) throw new RuntimeException('Export archive path is unsafe.');
    }

    private function containsPrivatePath(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) if ($this->containsPrivatePath($item)) return true;
            return false;
        }
        if (! is_string($value) || $value === '') return false;
        $normalized = str_replace('\\', '/', $value);
        $privateRoot = rtrim(str_replace('\\', '/', dirname(dirname(dirname($this->backupDirectory)))), '/');
        if ($privateRoot !== '' && ($normalized === $privateRoot || str_starts_with($normalized, $privateRoot . '/'))) return true;
        return preg_match('/^(?:[A-Za-z]:\/|\/\/|\/)/', $normalized) === 1 && ! preg_match('#^(?:https?|ftps?)://#i', $normalized);
    }

    private function assertNoSymlinkAncestor(string $path): void
    {
        $cursor = $path;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) throw new RuntimeException('Export storage is unavailable.');
            if (is_dir($cursor)) return;
            $cursor = dirname($cursor);
        }
        if (is_link($cursor)) throw new RuntimeException('Export storage is unavailable.');
    }

    /** @return resource */
    private function openPrivateFile(string $path, string $error): mixed
    {
        $previousUmask = umask(0077);
        try { $stream = fopen($path, 'xb'); }
        finally { umask($previousUmask); }
        if ($stream === false || ! chmod($path, 0600)) {
            if (is_resource($stream)) fclose($stream);
            @unlink($path);
            throw new RuntimeException($error);
        }
        return $stream;
    }

    private function validTarChecksum(string $header): bool
    {
        $field = trim(substr($header, 148, 8), "\0 ");
        if ($field === '' || preg_match('/^[0-7]+$/', $field) !== 1) return false;
        return array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8))) === octdec($field);
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root) || is_link($root)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($root);
    }
}
