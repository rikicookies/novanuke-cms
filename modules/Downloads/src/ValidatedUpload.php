<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

final class ValidatedUpload
{
    public function __construct(
        public readonly string $temporaryPath, public readonly string $originalName,
        public readonly string $extension, public readonly string $mimeType, public readonly int $size,
    ) {
    }
}
