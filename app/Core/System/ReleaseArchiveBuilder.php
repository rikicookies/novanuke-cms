<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ReleaseArchiveBuilder
{
    private const DIRECTORY_MODE = 0755;
    private const FILE_MODE = 0644;

    public function build(string $sourceRoot, string $archivePath): int
    {
        $sourceRoot = realpath($sourceRoot) ?: '';
        if ($sourceRoot === '' || ! is_dir($sourceRoot)) throw new RuntimeException('Release source directory is unavailable.');
        if (! class_exists(ZipArchive::class)) throw new RuntimeException('The PHP ZIP extension is required to build a release archive.');

        $entries = $this->entries($sourceRoot);
        $parent = dirname($archivePath);
        if (! is_dir($parent) && ! mkdir($parent, self::DIRECTORY_MODE, true) && ! is_dir($parent)) throw new RuntimeException('Release archive destination is unavailable.');
        if (is_link($archivePath)) throw new RuntimeException('Release archive destination must not be a symbolic link.');

        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Release archive could not be opened.');
        try {
            foreach ($entries as $entry) {
                $relative = $entry['relative'];
                $zipName = $entry['directory'] ? rtrim($relative, '/') . '/' : $relative;
                $mode = $entry['directory'] ? (040000 | self::DIRECTORY_MODE) : (0100000 | self::FILE_MODE);
                $added = $entry['directory'] ? $zip->addEmptyDir($relative) : $zip->addFile($entry['path'], $relative);
                if (! $added || ! $zip->setExternalAttributesName($zipName, ZipArchive::OPSYS_UNIX, $mode << 16)) throw new RuntimeException("Release archive entry could not be written: {$relative}");
            }
            if ($zip->close() !== true) throw new RuntimeException('Release archive could not be finalized.');
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($archivePath);
            throw $error;
        }

        return count($entries);
    }

    /** @return list<array{path:string,relative:string,directory:bool}> */
    private function entries(string $sourceRoot): array
    {
        $directories = [];
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
            if ($this->excluded($relative, $item->isDir())) continue;
            if ($item->isLink()) throw new RuntimeException("Release source may not contain symbolic links: {$relative}");
            if ($item->isDir()) $directories[] = ['path' => $item->getPathname(), 'relative' => $relative, 'directory' => true];
            elseif ($item->isFile()) $files[] = ['path' => $item->getPathname(), 'relative' => $relative, 'directory' => false];
        }
        usort($directories, static fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));
        usort($files, static fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));
        return [...$directories, ...$files];
    }

    private function excluded(string $relative, bool $directory): bool
    {
        $normalized = trim(str_replace('\\', '/', $relative), '/');
        $parts = explode('/', $normalized);
        $base = end($parts);
        foreach (['.git', '.github', 'vendor', '.idea', '.vscode', '.phpunit.cache', '.codex'] as $excludedDirectory) {
            if (in_array($excludedDirectory, $parts, true)) return true;
        }
        if ($normalized === 'ECC-AUDIT.md' || $normalized === 'storage/installed.lock') return true;
        if ($normalized === '.env' || (str_starts_with($base, '.env.') && ! in_array($base, ['.env.example', '.env.testing.example'], true))) return true;
        if (preg_match('/\.zip$/i', $base) === 1) return true;
        if (str_starts_with($normalized, 'public/assets/themes/')) return true;
        foreach (['storage/cache', 'storage/logs', 'storage/sessions', 'storage/private', 'public/uploads'] as $runtimeRoot) {
            if ($normalized === $runtimeRoot) return false;
            if (str_starts_with($normalized, $runtimeRoot . '/')) return $directory ? false : ! in_array($base, ['.gitkeep', '.htaccess'], true);
        }
        return false;
    }
}
