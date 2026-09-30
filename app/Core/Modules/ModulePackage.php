<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

final readonly class ModulePackage
{
    public function __construct(
        public ModuleManifest $manifest,
        public string $directory,
        public int $files,
        public int $uncompressedBytes,
    ) {
    }
}
