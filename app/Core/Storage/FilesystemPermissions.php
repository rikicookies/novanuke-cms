<?php

declare(strict_types=1);

namespace NovaNuke\Core\Storage;

use RuntimeException;

final class FilesystemPermissions
{
    public const PUBLIC_DIRECTORY = 0755;
    public const PUBLIC_FILE = 0644;
    public const PRIVATE_DIRECTORY = 0700;
    public const PRIVATE_FILE = 0600;

    public static function ensureDirectory(string $directory, int $mode): string
    {
        if (is_link($directory)) throw new RuntimeException('Storage directory must not be a symbolic link.');
        if (! is_dir($directory) && ! mkdir($directory, $mode, true) && ! is_dir($directory)) throw new RuntimeException('Storage directory could not be created.');
        if (! @chmod($directory, $mode)) throw new RuntimeException('Storage directory permissions could not be set.');
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($root) || ! is_writable($root)) throw new RuntimeException('Storage directory is unavailable or not writable.');
        return $root;
    }

    public static function setFileMode(string $path, int $mode): void
    {
        if (! is_file($path) || is_link($path) || ! @chmod($path, $mode)) throw new RuntimeException('Stored file permissions could not be set.');
    }

    private function __construct()
    {
    }
}
