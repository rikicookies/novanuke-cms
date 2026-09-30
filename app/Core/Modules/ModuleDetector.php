<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use JsonException;
use RuntimeException;

final class ModuleDetector
{
    public function __construct(private readonly string $modulesPath)
    {
    }

    /** @return array<string, ModuleManifest> */
    public function detect(): array
    {
        $manifests = [];
        $directories = glob(rtrim($this->modulesPath, '/') . '/*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_STRING);

        foreach ($directories as $directory) {
            $file = $directory . '/module.json';
            if (! is_file($file)) {
                continue;
            }
            try {
                $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException("Invalid module manifest JSON: {$file}", previous: $error);
            }
            if (! is_array($data)) {
                throw new RuntimeException("Module manifest must contain an object: {$file}");
            }
            $manifest = ModuleManifest::fromArray($data, $directory);
            if (isset($manifests[$manifest->slug])) {
                throw new RuntimeException("Duplicate module slug: {$manifest->slug}");
            }
            $manifests[$manifest->slug] = $manifest;
        }

        return $manifests;
    }
}
