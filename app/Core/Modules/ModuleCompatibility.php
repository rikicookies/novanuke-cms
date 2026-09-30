<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

final readonly class ModuleCompatibility
{
    public function __construct(
        public bool $compatible,
        public ?string $reason = null,
    ) {
    }
}
