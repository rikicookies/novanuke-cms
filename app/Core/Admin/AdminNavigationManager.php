<?php

declare(strict_types=1);

namespace NovaNuke\Core\Admin;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Settings\SettingsRepository;

final class AdminNavigationManager
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly EventDispatcher $events,
        private readonly ViewRenderer $views,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function boot(): void
    {
        $path = '/' . trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        if ($path !== '/') $path = rtrim($path, '/');
        $adminArea = $path === '/admin' || str_starts_with($path, '/admin/');
        $this->views->addGlobal('admin_area', $adminArea);
        $this->views->addGlobal('admin_path', $path);
        $this->views->addGlobal('admin_navigation', $adminArea ? $this->navigation($path) : []);
    }

    /** @return list<array{slug:string,label:string,items:list<array<string,mixed>>}> */
    private function navigation(string $path): array
    {
        $user = $this->auth->user();
        if ($user === null || ! $this->authorization->allows((int) $user['id'], 'admin.access')) return [];

        $items = [
            $this->item('admin.navigation.dashboard', '/admin', 'admin.access', 'dashboard', 'overview'),
            $this->item('admin.navigation.users', '/admin/users', 'users.view', 'users', 'community'),
            $this->item('admin.navigation.memberships', '/admin/memberships', 'memberships.manage', 'badge', 'community'),
            $this->item('admin.navigation.roles', '/admin/roles', 'roles.view', 'shield', 'community'),
            $this->item('admin.navigation.themes', '/admin/themes', 'themes.manage', 'palette', 'appearance'),
            $this->item('admin.navigation.menus', '/admin/menus', 'menus.manage', 'menu', 'appearance'),
            $this->item('admin.navigation.blocks', '/admin/blocks', 'blocks.manage', 'blocks', 'appearance'),
            $this->item('admin.navigation.modules', '/admin/modules', 'modules.manage', 'module', 'system'),
            $this->item('admin.navigation.settings', '/admin/settings', 'settings.manage', 'settings', 'system'),
            $this->item('admin.navigation.registration', '/admin/settings/users', 'settings.manage', 'user-settings', 'system'),
            $this->item('admin.navigation.logs', '/admin/logs', 'logs.view', 'logs', 'system'),
            $this->item('admin.navigation.system_information', '/admin/system', 'settings.manage', 'system', 'system'),
        ];

        $modules = new AdminMenuBuilding();
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING, $modules);
        foreach ($modules->items() as $item) {
            $items[] = $this->moduleItem($item);
        }

        $groups = [
            'overview' => ['label' => 'admin.group.overview', 'items' => []],
            'content' => ['label' => 'admin.group.content', 'items' => []],
            'resources' => ['label' => 'admin.group.resources', 'items' => []],
            'community' => ['label' => 'admin.group.community', 'items' => []],
            'appearance' => ['label' => 'admin.group.appearance', 'items' => []],
            'system' => ['label' => 'admin.group.system', 'items' => []],
            'modules' => ['label' => 'admin.group.other_modules', 'items' => []],
        ];
        foreach ($items as $item) {
            if (! $this->authorization->allows((int) $user['id'], (string) $item['permission'])) continue;
            $item['active'] = $this->active($path, (string) $item['url']);
            $group = isset($groups[$item['group']]) ? $item['group'] : 'modules';
            $groups[$group]['items'][] = $item;
        }

        $result = [];
        foreach ($groups as $slug => $group) {
            if ($group['items'] !== []) $result[] = ['slug' => $slug, 'label' => $group['label'], 'items' => $group['items']];
        }
        return $this->applySavedOrder($result);
    }


    /** @param list<array{slug:string,label:string,items:list<array<string,mixed>>}> $groups
     *  @return list<array{slug:string,label:string,items:list<array<string,mixed>>}>
     */
    private function applySavedOrder(array $groups): array
    {
        $raw = $this->settings->string('admin.navigation.order', '');
        if ($raw === '') return $groups;

        $saved = json_decode($raw, true);
        if (! is_array($saved)) return $groups;

        $groupRanks = [];
        $itemRanks = [];
        foreach ($saved as $groupIndex => $entry) {
            if (! is_array($entry) || ! isset($entry['slug']) || ! is_string($entry['slug'])) continue;
            $groupRanks[$entry['slug']] = (int) $groupIndex;
            foreach (($entry['items'] ?? []) as $itemIndex => $url) {
                if (is_string($url) && str_starts_with($url, '/admin')) {
                    $itemRanks[$entry['slug']][$url] = (int) $itemIndex;
                }
            }
        }

        foreach ($groups as &$group) {
            $slug = (string) $group['slug'];
            if (! isset($itemRanks[$slug])) continue;
            usort($group['items'], static function (array $left, array $right) use ($itemRanks, $slug): int {
                $leftRank = $itemRanks[$slug][(string) $left['url']] ?? PHP_INT_MAX;
                $rightRank = $itemRanks[$slug][(string) $right['url']] ?? PHP_INT_MAX;
                return $leftRank <=> $rightRank;
            });
        }
        unset($group);

        usort($groups, static function (array $left, array $right) use ($groupRanks): int {
            $leftRank = $groupRanks[(string) $left['slug']] ?? PHP_INT_MAX;
            $rightRank = $groupRanks[(string) $right['slug']] ?? PHP_INT_MAX;
            return $leftRank <=> $rightRank;
        });
        return $groups;
    }

    /** @return array{label:string,url:string,permission:string,icon:string,group:string} */
    private function item(string $label, string $url, string $permission, string $icon, string $group): array
    {
        return compact('label', 'url', 'permission', 'icon', 'group');
    }

    /** @param array{label:string,url:string,permission:string,icon:string,group:string} $item @return array{label:string,url:string,permission:string,icon:string,group:string} */
    private function moduleItem(array $item): array
    {
        $slug = explode('.', (string) $item['permission'])[0];
        $map = [
            'news' => ['newspaper', 'content'], 'pages' => ['page', 'content'], 'comments' => ['comments', 'content'],
            'downloads' => ['download', 'resources'], 'web-links' => ['link', 'resources'],
            'search' => ['search', 'resources'],
        ];
        if ($item['icon'] === 'module' && isset($map[$slug])) $item['icon'] = $map[$slug][0];
        if ($item['group'] === 'modules' && isset($map[$slug])) $item['group'] = $map[$slug][1];
        return $item;
    }

    private function active(string $path, string $url): bool
    {
        if ($url === '/admin') return $path === '/admin';
        if ($url === '/admin/settings') return $path === '/admin/settings';
        return $path === $url || str_starts_with($path, $url . '/');
    }
}
