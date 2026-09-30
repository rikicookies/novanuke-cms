<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use Closure;

final class ModuleHomepageRegistry
{
    /** @param Closure():array<string,array<string,mixed>> $inventory
     *  @param Closure(string):bool $settingEnabled
     */
    public function __construct(
        private readonly Closure $inventory,
        private readonly Closure $settingEnabled,
    ) {
    }

    /** @return array<string,string> slug => translation key */
    public function options(): array
    {
        $options = ['home' => 'settings.homepage_default'];
        foreach ($this->eligible() as $slug => $navigation) $options[$slug] = $navigation['label'];
        return $options;
    }

    public function url(string $slug): ?string
    {
        if ($slug === 'home') return null;
        return $this->eligible()[$slug]['url'] ?? null;
    }

    /** @return array<string,array{label:string,url:string,icon:?string,order:int,audience:string,public_landing:bool,enabled_setting:?string}> */
    private function eligible(): array
    {
        $items = [];
        foreach (($this->inventory)() as $slug => $module) {
            $manifest = $module['manifest'] ?? null;
            if (! $manifest instanceof ModuleManifest
                || ! (bool) ($module['installed'] ?? false)
                || ! (bool) ($module['enabled'] ?? false)
                || ! (bool) ($module['compatible'] ?? false)
                || ($module['last_error'] ?? null) !== null
                || (string) ($module['audience'] ?? 'public') !== 'public'
                || $manifest->navigation === null
                || ! $manifest->navigation['public_landing']
                || $manifest->navigation['audience'] !== 'public'
                || $manifest->navigation['url'] === '/') {
                continue;
            }
            $setting = $manifest->navigation['enabled_setting'];
            if ($setting !== null && ! ($this->settingEnabled)($setting)) continue;
            $items[$slug] = $manifest->navigation;
        }
        uasort($items, static fn (array $left, array $right): int =>
            [$left['order'], $left['label']] <=> [$right['order'], $right['label']]
        );
        return $items;
    }
}
