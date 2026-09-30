<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Routing\Router;

final readonly class ModuleContext
{
    public function __construct(
        public ModuleManifest $manifest,
        public Container $container,
        public Router $router,
        public EventDispatcher $events,
        public string $basePath,
    ) {
    }
}
