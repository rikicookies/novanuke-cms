<?php

declare(strict_types=1);

namespace NovaNuke\Core\Config;

use RuntimeException;

final class ConfigLoader
{
    public function __construct(private readonly string $directory)
    {
    }

    public function load(): ConfigRepository
    {
        $items = [];
        $files = glob($this->directory . '/*.php') ?: [];

        foreach ($files as $file) {
            $config = require $file;

            if (! is_array($config)) {
                throw new RuntimeException("Configuration file must return an array: {$file}");
            }

            $items[pathinfo($file, PATHINFO_FILENAME)] = $config;
        }

        return new ConfigRepository($items);
    }
}
