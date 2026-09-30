<?php

declare(strict_types=1);

namespace NovaNuke\Core\Config;

use InvalidArgumentException;

final class ConfigRepository
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);

        if ($value === $sentinel) {
            throw new InvalidArgumentException("Missing required configuration: {$key}");
        }

        return $value;
    }
}
