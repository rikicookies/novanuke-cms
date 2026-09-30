<?php

declare(strict_types=1);

namespace Modules\Pages\src;

final class PageRendering
{
    /** @param array<string,mixed> $page */
    public function __construct(public array $page)
    {
    }
}
