<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use NovaNuke\Core\Storage\FilesystemPermissions;
use RuntimeException;

final class StorageProvisioner
{
    /** @var list<string> */
    /** @var array<string,int> */
    private const REQUIRED_DIRECTORIES = [
        'storage' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'storage/cache' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'storage/logs' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'storage/sessions' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'storage/private' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'storage/private/downloads' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'storage/private/backups' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'storage/private/avatars' => FilesystemPermissions::PRIVATE_DIRECTORY,
        'public/uploads' => FilesystemPermissions::PUBLIC_DIRECTORY,
    ];

    /** @return list<string> */
    public function requiredDirectories(): array
    {
        return array_keys(self::REQUIRED_DIRECTORIES);
    }

    public function provision(string $rootPath): void
    {
        foreach (self::REQUIRED_DIRECTORIES as $directory => $mode) {
            $path = $rootPath . '/' . $directory;

            if (is_link($path)) {
                throw new RuntimeException("Required storage path must not be a symbolic link: {$directory}");
            }

            try {
                FilesystemPermissions::ensureDirectory($path, $mode);
            } catch (RuntimeException $error) {
                throw new RuntimeException("Unable to provision required storage directory: {$directory}", 0, $error);
            }
        }
    }
}
