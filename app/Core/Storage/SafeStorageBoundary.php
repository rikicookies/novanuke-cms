<?php

declare(strict_types=1);

namespace NovaNuke\Core\Storage;

use RuntimeException;

final class SafeStorageBoundary
{
    public static function ensureDirectory(string $directory, int $mode = FilesystemPermissions::PRIVATE_DIRECTORY): string
    {
        return FilesystemPermissions::ensureDirectory($directory, $mode);
    }

    public static function existingFile(string $directory, string $filename): string
    {
        if (is_link($directory)) {
            throw new RuntimeException('Storage directory must not be a symbolic link.');
        }

        $root = realpath($directory);
        $candidate = $directory . DIRECTORY_SEPARATOR . $filename;

        if ($root === false || ! is_dir($root) || is_link($candidate)) {
            throw new RuntimeException('Stored file is unavailable.');
        }

        $path = realpath($candidate);
        if ($path === false || ! is_file($path) || ! is_readable($path)
            || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Stored file is unavailable.');
        }

        return $path;
    }

    public static function assertPathInside(string $directory, string $path): string
    {
        if (is_link($directory) || is_link($path)) {
            throw new RuntimeException('Stored path must not use symbolic links.');
        }

        $root = realpath($directory);
        $resolved = realpath($path);
        if ($root === false || $resolved === false || ! is_file($resolved)
            || ! str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Stored path escaped its storage boundary.');
        }

        return $resolved;
    }

    private function __construct()
    {
    }
}
