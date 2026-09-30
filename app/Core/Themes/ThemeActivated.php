<?php

declare(strict_types=1);

namespace NovaNuke\Core\Themes;

final readonly class ThemeActivated
{
    public function __construct(public string $slug)
    {
    }
}
