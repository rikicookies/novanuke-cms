<?php

declare(strict_types=1);

namespace NovaNuke\Core\Storage;

use RuntimeException;

final class SafeStorageBoundary
{
    public static function ensureDirectory(string $directory, int $mode = 0770): string
    {
        if (is_link($directory)) {
            throw new RuntimeException('Storage directory must not be a symbolic link.');
        }

        if (! is_dir($directory) && ! mkdir($directory, $mode, true) && ! is_dir($directory)) {
            throw new RuntimeException('Storage directory could not be created.');
        }

        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('Storage directory is unavailable.');
        }

        if (! is_writable($root)) {
            throw new RuntimeException('Storage directory is not writable.');
        }

        return $root;
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
