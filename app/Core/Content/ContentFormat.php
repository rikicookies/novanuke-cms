<?php

declare(strict_types=1);

namespace NovaNuke\Core\Content;

use RuntimeException;

enum ContentFormat: string
{
    case Html = 'html';
    case Markdown = 'markdown';

    public static function fromInput(mixed $value, self $default = self::Html): self
    {
        if ($value === null || $value === '') return $default;
        return self::tryFrom((string) $value) ?? throw new RuntimeException('Invalid content format.');
    }
}
