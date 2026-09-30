<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use RuntimeException;

final class StorageProvisioner
{
    /** @var list<string> */
    private const REQUIRED_DIRECTORIES = [
        'storage',
        'storage/cache',
        'storage/logs',
        'storage/sessions',
        'storage/private',
        'storage/private/downloads',
        'storage/private/backups',
        'storage/private/avatars',
        'public/uploads',
    ];

    /** @return list<string> */
    public function requiredDirectories(): array
    {
        return self::REQUIRED_DIRECTORIES;
    }

    public function provision(string $rootPath): void
    {
        foreach (self::REQUIRED_DIRECTORIES as $directory) {
            $path = $rootPath . '/' . $directory;

            if (is_link($path)) {
                throw new RuntimeException("Required storage path must not be a symbolic link: {$directory}");
            }

            if (! is_dir($path) && ! mkdir($path, 0770, true) && ! is_dir($path)) {
                throw new RuntimeException("Unable to create required storage directory: {$directory}");
            }

            if (! is_writable($path)) {
                throw new RuntimeException("Required storage directory is not writable: {$directory}");
            }
        }
    }
}
