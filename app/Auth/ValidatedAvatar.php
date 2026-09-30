<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final readonly class ValidatedAvatar
{
    public function __construct(public string $temporaryPath, public string $extension, public string $mimeType, public int $size)
    {
    }
}
