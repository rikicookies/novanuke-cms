<?php

declare(strict_types=1);

namespace Modules\Media\src;

final readonly class ValidatedMedia
{
    public function __construct(public string $temporaryPath, public string $originalName, public string $extension, public string $mimeType, public int $size, public int $width, public int $height)
    {
    }
}
