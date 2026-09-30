<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http\Routing;

use Closure;

final class Route
{
    /** @param list<string> $methods */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly Closure $handler,
        public readonly ?string $name = null,
        public readonly ?string $owner = null,
    ) {
    }
}
