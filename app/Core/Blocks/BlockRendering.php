<?php

declare(strict_types=1);

namespace NovaNuke\Core\Blocks;

final class BlockRendering
{
    public ?string $html = null;

    /** @param array<string,mixed> $block */
    public function __construct(public readonly array $block) {}

    public function render(string $html): void { $this->html = $html; }
}
