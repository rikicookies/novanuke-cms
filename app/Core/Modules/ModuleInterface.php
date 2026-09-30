<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

interface ModuleInterface
{
    public function register(ModuleContext $context): void;

    public function boot(ModuleContext $context): void;
}
