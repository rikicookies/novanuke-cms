<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use RuntimeException;

final class BackupOperationLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $directory) {}

    public function acquire(): void
    {
        if (is_link($this->directory) || (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory))) {
            throw new RuntimeException('Backup storage is unavailable.');
        }
        $path = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '.admin-operation.lock';
        if (is_link($path)) throw new RuntimeException('Backup operation lock is unsafe.');
        $handle = @fopen($path, 'c');
        if ($handle === false || ! @flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) @fclose($handle);
            throw new RuntimeException('Another backup operation is already running.');
        }
        @chmod($path, 0600);
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct() { $this->release(); }
}
