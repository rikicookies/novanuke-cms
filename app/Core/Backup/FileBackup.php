<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class FileBackup
{
    /** @param array<string,string>|null $sources */
    public function __construct(
        private readonly string $rootPath,
        private readonly string $directory,
        private readonly ?array $sources = null,
    ) {
    }

    /** @return array{path:string,files:int,bytes:int,sha256:string,backup_set:string} */
    public function create(?string $backupSetId = null): array
    {
        $backupSetId = BackupSetId::normalize($backupSetId);
        $this->prepareDirectory();
        $files = $this->inventory();
        $suffix = bin2hex(random_bytes(4));
        $finalPath = $this->directory . '/novanuke-files-' . gmdate('Ymd-His') . "-{$suffix}.tar";
        $temporaryPath = $finalPath . '.part';
        $stream = fopen($temporaryPath, 'xb');
        if ($stream === false) throw new RuntimeException('Unable to create the file backup.');

        try {
            chmod($temporaryPath, 0600);
            $writer = new TarWriter($stream);
            $manifestFiles = [];
            $totalBytes = 0;
            foreach ($files as $archivePath => $sourcePath) {
                $size = filesize($sourcePath);
                $hash = hash_file('sha256', $sourcePath);
                if ($size === false || $hash === false) throw new RuntimeException("Unable to fingerprint backup source: {$archivePath}");
                $manifestFiles[] = ['path' => $archivePath, 'bytes' => $size, 'sha256' => $hash];
                $totalBytes += $size;
                $writer->addFile($archivePath, $sourcePath);
            }
            $manifest = json_encode([
                'format' => 2,
                'backup_set' => $backupSetId,
                'created_at' => gmdate(DATE_ATOM),
                'files' => $manifestFiles,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $writer->addString('NOVANUKE-BACKUP.json', $manifest);
            $writer->finish();
            if (! fflush($stream)) throw new RuntimeException('Unable to flush the file backup.');
            fclose($stream);
            $stream = null;
            if (! rename($temporaryPath, $finalPath)) throw new RuntimeException('Unable to finalize the file backup.');
            chmod($finalPath, 0600);
            $archiveHash = hash_file('sha256', $finalPath);
            if ($archiveHash === false) throw new RuntimeException('Unable to fingerprint the file backup.');
            return ['path' => $finalPath, 'files' => count($files), 'bytes' => $totalBytes, 'sha256' => $archiveHash, 'backup_set' => $backupSetId];
        } catch (Throwable $error) {
            if (is_resource($stream)) fclose($stream);
            if (is_file($temporaryPath)) unlink($temporaryPath);
            throw $error;
        }
    }

    private function prepareDirectory(): void
    {
        if (is_link($this->directory)) throw new RuntimeException('Private backup directory must not be a symbolic link.');
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the private backup directory.');
        }
        if (! is_writable($this->directory)) throw new RuntimeException('The private backup directory is not writable.');
    }

    /** @return array<string,string> */
    private function inventory(): array
    {
        $sources = $this->sources ?? [
            'modules' => $this->rootPath . '/modules',
            'themes' => $this->rootPath . '/themes',
            'public/uploads' => $this->rootPath . '/public/uploads',
            'storage/private/avatars' => $this->rootPath . '/storage/private/avatars',
            'storage/private/downloads' => $this->rootPath . '/storage/private/downloads',
            'storage/private/wiki' => $this->rootPath . '/storage/private/wiki',
        ];
        $files = [];
        foreach ($sources as $archiveRoot => $sourceRoot) {
            if (! is_dir($sourceRoot) || is_link($sourceRoot)) continue;
            $root = realpath($sourceRoot);
            if ($root === false) continue;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isLink() || ! $item->isFile()) continue;
                $path = $item->getRealPath();
                if ($path === false || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR)) continue;
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                $files[trim($archiveRoot, '/') . '/' . $relative] = $path;
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }
}
