<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use Closure;
use FilesystemIterator;
use RuntimeException;

final class DownloadOrphanCleaner
{
    /** @param Closure():array<int,string> $referencedNames */
    public function __construct(
        private readonly string $directory,
        private readonly Closure $referencedNames,
        private readonly int $graceSeconds = 86400,
    ) {
    }

    /** @return array{eligible:int,bytes:int,removed:int,recent:int} */
    public function run(bool $delete = false, ?int $now = null): array
    {
        if (! is_dir($this->directory)) return ['eligible' => 0, 'bytes' => 0, 'removed' => 0, 'recent' => 0];
        if ($delete && ! is_writable($this->directory)) throw new RuntimeException('Private download storage is not writable.');
        $referenced = array_fill_keys(($this->referencedNames)(), true);
        $now ??= time();
        $result = ['eligible' => 0, 'bytes' => 0, 'removed' => 0, 'recent' => 0];
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink() || ! $item->isFile()) continue;
            $name = $item->getFilename();
            if (! preg_match('/^[a-f0-9]{40}\.[a-z0-9]{2,5}$/', $name) || isset($referenced[$name])) continue;
            if ($item->getMTime() > $now - $this->graceSeconds) {
                $result['recent']++;
                continue;
            }
            $result['eligible']++;
            $result['bytes'] += $item->getSize();
            if (! $delete) continue;
            $realRoot = realpath($this->directory);
            $realPath = $item->getRealPath();
            if ($realRoot === false || $realPath === false || ! str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Orphan path escaped private download storage.');
            }
            if (! unlink($realPath)) throw new RuntimeException("Unable to remove orphaned download: {$name}");
            $result['removed']++;
        }
        return $result;
    }
}
