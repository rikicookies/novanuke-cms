<?php

declare(strict_types=1);

namespace NovaNuke\Core\Admin;

use InvalidArgumentException;

final class AdminMenuBuilding
{
    /** @var list<array{label:string,url:string,permission:string,icon:string,group:string}> */
    private array $items = [];

    public function add(
        string $label,
        string $url,
        string $permission,
        string $icon = 'module',
        string $group = 'modules',
    ): void
    {
        if ($label === '' || ! str_starts_with($url, '/') || $permission === ''
            || ! preg_match('/^[a-z][a-z0-9-]{0,39}$/', $icon)
            || ! preg_match('/^[a-z][a-z0-9-]{0,39}$/', $group)) {
            throw new InvalidArgumentException('Invalid administrative menu item.');
        }
        $this->items[] = compact('label', 'url', 'permission', 'icon', 'group');
    }

    public function items(): array
    {
        return $this->items;
    }
}
