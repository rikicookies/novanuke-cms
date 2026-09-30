<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http\Routing;

use Closure;
use InvalidArgumentException;
use NovaNuke\Core\Http\Request;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];
    private ?string $owner = null;

    public function beginOwner(string $owner): void {$this->owner = $owner;}
    public function endOwner(): void {$this->owner = null;}
    public function removeOwner(string $owner): void {$this->routes=array_values(array_filter($this->routes,static fn(Route $route):bool=>$route->owner!==$owner));}

    public function get(string $path, Closure $handler, ?string $name = null): void
    {
        $this->add(['GET', 'HEAD'], $path, $handler, $name);
    }

    public function post(string $path, Closure $handler, ?string $name = null): void
    {
        $this->add(['POST'], $path, $handler, $name);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $path, Closure $handler, ?string $name = null): void
    {
        $normalized = '/' . trim($path, '/');
        $normalized = $normalized === '/' ? '/' : $normalized;
        $methods = array_values(array_unique(array_map(static fn (string $method): string => strtoupper($method), $methods)));
        if ($methods === []) {
            throw new InvalidArgumentException('A route must declare at least one HTTP method.');
        }

        foreach ($this->routes as $route) {
            if ($name !== null && $route->name === $name) {
                throw new InvalidArgumentException("Duplicate route name: {$name}");
            }
            if ($route->path === $normalized && array_intersect($route->methods, $methods) !== []) {
                $owner = $this->owner === null ? 'core' : "module {$this->owner}";
                throw new InvalidArgumentException("Route collision for {$normalized} while registering {$owner}.");
            }
        }

        $this->routes[] = new Route($methods, $normalized, $handler, $name, $this->owner);
    }

    public function match(Request $request): RouteMatch
    {
        $pathMatches = [];

        foreach ($this->routes as $route) {
            $pattern = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
                static fn (array $match): string => '(?P<' . $match[1] . '>[^/]+)',
                $route->path,
            );
            $pattern = '#^' . $pattern . '$#';

            if (! preg_match($pattern, $request->path(), $matches)) {
                continue;
            }

            $pathMatches[] = $route;

            if (! in_array($request->method(), $route->methods, true)) {
                continue;
            }

            $parameters = array_filter(
                $matches,
                static fn (string|int $key): bool => is_string($key),
                ARRAY_FILTER_USE_KEY,
            );

            return new RouteMatch($route, $parameters);
        }

        if ($pathMatches !== []) {
            throw new MethodNotAllowed('Method not allowed.');
        }

        throw new RouteNotFound('Route not found.');
    }
}
