<?php

declare(strict_types=1);

namespace NovaNuke\Core\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class CacheManager
{
    public function __construct(private readonly string $cachePath)
    {
    }

    public function clear(): int
    {
        if (! is_dir($this->cachePath) && ! mkdir($this->cachePath, 0750, true) && ! is_dir($this->cachePath)) {
            throw new RuntimeException('Unable to create the cache directory.');
        }
        $root = realpath($this->cachePath);
        if ($root === false || $root === DIRECTORY_SEPARATOR || ! is_writable($root)) {
            throw new RuntimeException('Refusing to clear an unsafe or unwritable cache path.');
        }

        $removed = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $success = $item->isDir() && ! $item->isLink() ? rmdir($path) : unlink($path);
            if (! $success) throw new RuntimeException('Unable to remove a cached item.');
            $removed++;
        }

        if (function_exists('opcache_reset')) @opcache_reset();
        return $removed;
    }

    /** @return array{path_exists:bool,writable:bool,files:int} */
    public function status(): array
    {
        $files = 0;
        if (is_dir($this->cachePath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cachePath, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $item) if ($item->isFile()) $files++;
        }
        return ['path_exists' => is_dir($this->cachePath), 'writable' => is_writable($this->cachePath), 'files' => $files];
    }
}
