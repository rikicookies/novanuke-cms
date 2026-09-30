<?php

declare(strict_types=1);

namespace NovaNuke\Core\Themes;

use JsonException;
use RuntimeException;

final class ThemeDetector
{
    public function __construct(private readonly string $themesPath)
    {
    }

    /** @return array<string, ThemeManifest> */
    public function detect(): array
    {
        $themes = [];
        $directories = glob(rtrim($this->themesPath, '/') . '/*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $file = $directory . '/theme.json';
            if (! is_file($file)) {
                continue;
            }
            try {
                $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException("Invalid theme manifest JSON: {$file}", previous: $error);
            }
            if (! is_array($data)) {
                throw new RuntimeException("Theme manifest must contain an object: {$file}");
            }
            $manifest = ThemeManifest::fromArray($data, $directory);
            if (isset($themes[$manifest->slug])) {
                throw new RuntimeException("Duplicate theme slug: {$manifest->slug}");
            }
            $themes[$manifest->slug] = $manifest;
        }

        return $themes;
    }
}
