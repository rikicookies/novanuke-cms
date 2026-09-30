<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http;

final class Request
{
    private const MAX_QUERY_KEYS = 200;
    private const MAX_REQUEST_KEYS = 500;
    private const MAX_NESTING_DEPTH = 8;

    /** @param array<string, string> $attributes */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query = [],
        private readonly array $request = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly array $server = [],
        private array $attributes = [],
    ) {
    }

    public static function capture(): self
    {
        self::assertInputShape($_GET, self::MAX_QUERY_KEYS, 'query');
        self::assertInputShape($_POST, self::MAX_REQUEST_KEYS, 'request');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $_SERVER['REQUEST_URI'] ?? '/',
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $_SERVER,
        );
    }

    public static function create(string $method, string $uri): self
    {
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return new self(strtoupper($method), $uri, $query);
    }


    /** @param array<mixed> $input */
    private static function assertInputShape(array $input, int $maxKeys, string $label): void
    {
        if (count($input) > $maxKeys) {
            throw new \RuntimeException("Too many {$label} parameters.");
        }

        $walk = static function (array $values, int $depth) use (&$walk, $label): void {
            if ($depth > self::MAX_NESTING_DEPTH) {
                throw new \RuntimeException("{$label} parameters are nested too deeply.");
            }

            foreach ($values as $value) {
                if (is_array($value)) {
                    $walk($value, $depth + 1);
                }
            }
        };

        $walk($input, 1);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        $encodedPath = (string) parse_url($this->uri, PHP_URL_PATH);
        if (preg_match('/%(?:2f|5c|00)/i', $encodedPath) === 1) {
            throw new \InvalidArgumentException('Request path contains an unsafe encoded separator or null byte.');
        }
        $path = rawurldecode($encodedPath);
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Request path contains unsafe characters.');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allInput(): array
    {
        return array_replace($this->query, $this->request);
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);

        return $clone;
    }

    public function ip(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function referer(): string
    {
        $value = (string) ($this->server['HTTP_REFERER'] ?? '');
        return strlen($value) <= 2048 && ! preg_match('/[\x00-\x1F\x7F]/', $value) ? $value : '';
    }
}
