<?php

declare(strict_types=1);

namespace NovaNuke\Core\Developer;

use JsonException;
use NovaNuke\Core\Modules\ModuleManifest;

final class ModuleInspector
{
    /** @return array<string,mixed> */
    public function inspect(string $modulePath): array
    {
        $modulePath = rtrim($modulePath, '/\\');
        $manifestFile = $modulePath . '/module.json';
        if (! is_file($manifestFile)) throw new \RuntimeException('module.json is missing.');
        try {
            $data = json_decode((string) file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new \RuntimeException('Invalid module.json: ' . $error->getMessage(), 0, $error);
        }
        if (! is_array($data)) throw new \RuntimeException('module.json must contain an object.');
        $manifest = ModuleManifest::fromArray($data, $modulePath);

        $routes = [];
        $listens = [];
        $dispatches = [];
        foreach (glob($modulePath . '/src/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            foreach ($this->routes($source) as $route) $routes[] = $route;
            foreach ($this->eventConstants($source, 'listen') as $event) $listens[] = $event;
            foreach ($this->eventConstants($source, 'dispatch') as $event) $dispatches[] = $event;
        }
        $routes = $this->uniqueRows($routes, static fn(array $r): string => $r['method'] . ' ' . $r['path'] . ' ' . ($r['name'] ?? ''));
        $listens = array_values(array_unique($listens)); sort($listens);
        $dispatches = array_values(array_unique($dispatches)); sort($dispatches);

        $migrations = glob($modulePath . '/database/migrations/*.php') ?: []; sort($migrations);
        $catalogues = glob($modulePath . '/language/*.json') ?: []; sort($catalogues);

        return [
            'directory' => basename($modulePath),
            'name' => $manifest->name,
            'slug' => $manifest->slug,
            'version' => $manifest->version,
            'api_version' => $manifest->apiVersion,
            'cms_min_version' => $manifest->cmsMinVersion,
            'php_min_version' => $manifest->phpMinVersion,
            'provider' => $manifest->provider,
            'dependencies' => $manifest->dependencies,
            'permissions' => $manifest->permissions,
            'declared_events' => $manifest->events,
            'routes' => $routes,
            'listens' => $listens,
            'dispatches' => $dispatches,
            'migrations' => array_map('basename', $migrations),
            'catalogues' => array_map('basename', $catalogues),
            'readme' => is_file($modulePath . '/README.md'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function inventory(string $modulesPath): array
    {
        $items = [];
        foreach (glob(rtrim($modulesPath, '/\\') . '/*/module.json') ?: [] as $file) {
            try { $items[] = $this->inspect(dirname($file)); } catch (\Throwable) { continue; }
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $items;
    }

    /** @return list<array{method:string,path:string,name:?string}> */
    private function routes(string $source): array
    {
        $rows = [];
        if (preg_match_all('/(?:->router|\$router)\s*->\s*(get|post|put|patch|delete)\s*\(\s*([\'\"])([^\'\"]+)\2[\s\S]*?\)\s*;/i', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $chunk = $match[0];
                $name = null;
                if (preg_match('/,\s*([\'\"])([a-z][a-z0-9.-]*)\1\s*\)\s*;/i', $chunk, $named)) $name = $named[2];
                $rows[] = ['method' => strtoupper($match[1]), 'path' => $match[3], 'name' => $name];
            }
        }
        return $rows;
    }

    /** @return list<string> */
    private function eventConstants(string $source, string $method): array
    {
        $events = [];
        if (preg_match_all('/->' . preg_quote($method, '/') . '\s*\(\s*(?:\\\\?NovaNuke\\\\Core\\\\Events\\\\)?EventName::([A-Z0-9_]+)/', $source, $matches)) {
            $events = $matches[1];
        }
        return array_values(array_unique($events));
    }

    /** @template T of array @param list<T> $rows @param callable(T):string $key @return list<T> */
    private function uniqueRows(array $rows, callable $key): array
    {
        $out = []; $seen = [];
        foreach ($rows as $row) { $id = $key($row); if (isset($seen[$id])) continue; $seen[$id] = true; $out[] = $row; }
        return $out;
    }
}
