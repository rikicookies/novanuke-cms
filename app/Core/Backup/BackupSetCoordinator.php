<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use NovaNuke\Core\Version;
use PDO;
use RuntimeException;
use Throwable;

final class BackupSetCoordinator
{
    /** @param null|callable(string):void $fault */
    public function __construct(
        private readonly PDO $database,
        private readonly string $rootPath,
        private readonly string $directory,
        private readonly mixed $fault = null,
    ) {
    }

    /** @return array{backup_set_id:string,manifest:string,database:string,files:string} */
    public function create(): array
    {
        $setId = BackupSetId::generate();
        $startedAt = gmdate(DATE_ATOM);
        $base = rtrim($this->directory, '/\\');
        $staging = $base . DIRECTORY_SEPARATOR . '.incomplete-' . $setId;
        $final = $base . DIRECTORY_SEPARATOR . $setId;
        $published = false;

        $this->prepareBase($base);
        if (! mkdir($staging, 0700) || ! is_dir($staging)) {
            throw new RuntimeException('Unable to create backup-set staging directory.');
        }

        try {
            $databaseStartedAt = gmdate(DATE_ATOM);
            $this->inject('before_database_backup');
            $databasePath = (new DatabaseBackup($this->database, $staging))->create($setId);
            $this->inject('after_database_backup');
            $databaseCompletedAt = gmdate(DATE_ATOM);

            $filesStartedAt = gmdate(DATE_ATOM);
            $this->inject('before_file_backup');
            $files = (new FileBackup($this->rootPath, $staging))->create($setId);
            $this->inject('after_file_backup');
            $filesCompletedAt = gmdate(DATE_ATOM);

            $verifier = new BackupVerifier($staging);
            $database = $verifier->verifyDatabase($databasePath);
            $archive = $verifier->verifyFileArchive($files['path']);
            if (! hash_equals($database['backup_set'], $setId) || ! hash_equals($archive['backup_set'], $setId)) {
                throw new RuntimeException('Backup components do not belong to the requested backup set.');
            }

            $this->inject('before_manifest_publish');
            $manifestPath = $staging . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = [
                'format' => 1,
                'backup_set_id' => $setId,
                'status' => 'complete',
                'started_at' => $startedAt,
                'completed_at' => gmdate(DATE_ATOM),
                'cms_version' => Version::CURRENT,
                'php_version' => PHP_VERSION,
                'phases' => [
                    'database' => ['started_at' => $databaseStartedAt, 'completed_at' => $databaseCompletedAt],
                    'files' => ['started_at' => $filesStartedAt, 'completed_at' => $filesCompletedAt],
                ],
                'database' => [
                    'driver' => (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME),
                    'server_version' => $this->safeServerVersion(),
                    'strategy' => 'pdo-repeatable-read-consistent-snapshot',
                    'snapshot' => $database['snapshot'],
                ],
                'components' => [
                    'database' => $this->component($databasePath, $database['bytes'], $database['sha256']),
                    'files' => $this->component($files['path'], filesize($files['path']), $files['sha256']),
                ],
                'included' => ['database', 'modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki'],
                'excluded' => ['.env', 'vendor', '.git', 'storage/cache', 'storage/logs', 'storage/sessions', 'storage/private/backups'],
                'warnings' => $database['snapshot_consistent'] ? [] : ['Database contains non-transactional tables; cross-table snapshot consistency is not guaranteed.'],
            ];
            $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($manifestPath . '.part', $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new RuntimeException('Unable to write backup-set manifest.');
            }
            @chmod($manifestPath . '.part', 0600);
            if (! rename($manifestPath . '.part', $manifestPath)) {
                throw new RuntimeException('Unable to finalize backup-set manifest.');
            }
            (new BackupVerifier($staging))->verifyManifest($manifestPath, true);
            if (! rename($staging, $final)) {
                throw new RuntimeException('Unable to publish the completed backup set.');
            }
            $published = true;
            $this->inject('after_manifest_publish');

            return [
                'backup_set_id' => $setId,
                'manifest' => $final . DIRECTORY_SEPARATOR . 'manifest.json',
                'database' => $final . DIRECTORY_SEPARATOR . basename($databasePath),
                'files' => $final . DIRECTORY_SEPARATOR . basename($files['path']),
            ];
        } catch (Throwable $error) {
            if (is_dir($staging)) $this->removeTree($staging);
            if ($published && is_dir($final)) $this->removeTree($final);
            throw $error;
        }
    }

    /** @return array{name:string,bytes:int,sha256:string,backup_set_id:string} */
    private function component(string $path, int|false $bytes, string $sha256): array
    {
        if ($bytes === false) throw new RuntimeException('Unable to inspect a backup component.');
        return ['name' => basename($path), 'bytes' => $bytes, 'sha256' => $sha256, 'backup_set_id' => $this->setIdFromPath($path)];
    }

    private function setIdFromPath(string $path): string
    {
        if (str_ends_with($path, '.sql')) return (new BackupVerifier(dirname($path)))->verifyDatabase($path)['backup_set'];
        return (new BackupVerifier(dirname($path)))->verifyFileArchive($path)['backup_set'];
    }

    private function safeServerVersion(): string
    {
        try { return (string) $this->database->getAttribute(PDO::ATTR_SERVER_VERSION); } catch (Throwable) { return 'unknown'; }
    }

    private function prepareBase(string $base): void
    {
        if (is_link($base)) throw new RuntimeException('Private backup directory must not be a symbolic link.');
        if (! is_dir($base) && ! mkdir($base, 0700, true) && ! is_dir($base)) throw new RuntimeException('Unable to create the private backup directory.');
        if (! is_writable($base)) throw new RuntimeException('The private backup directory is not writable.');
    }

    private function inject(string $stage): void
    {
        if ($this->fault !== null) ($this->fault)($stage);
    }

    private function removeTree(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($root);
    }
}
