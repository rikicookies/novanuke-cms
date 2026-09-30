<?php

declare(strict_types=1);

namespace NovaNuke\Core\Menus;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Modules\ModuleRouteAccess;
use PDO;
use RuntimeException;

final class MenuManager
{
    public function __construct(
        private readonly PDO $database,
        private readonly MenuRepository $repository,
        private readonly MenuUrlResolver $urls,
        private readonly MenuTreeBuilder $trees,
        private readonly AuthManager $auth,
        private readonly ModuleRouteAccess $moduleAccess,
        private readonly ViewRenderer $views,
    ) {
    }

    public function all(): array
    {
        $menus = $this->repository->all();
        foreach ($menus as &$menu) {
            $menu['tree'] = $this->trees->build($menu['items']);
        }
        unset($menu);
        return $menus;
    }
    public function roles(): array { return $this->repository->roles(); }

    /** @param array<string,mixed> $input */
    public function saveMenu(array $input): int
    {
        $id = $this->id($input['id'] ?? null);
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $description = trim((string) ($input['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Menu name is required and must not exceed 120 characters.');
        }
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $slug)) {
            throw new RuntimeException('Menu slug must use lowercase letters, numbers and hyphens.');
        }
        if (mb_strlen($description) > 255) {
            throw new RuntimeException('Menu description must not exceed 255 characters.');
        }
        return $this->repository->saveMenu($id, [
            'name' => $name,
            'slug' => $slug,
            'description' => $description === '' ? null : $description,
            'enabled' => ($input['enabled'] ?? null) === '1' ? 1 : 0,
        ]);
    }

    /** @param array<string,mixed> $input */
    public function saveItem(array $input): int
    {
        $id = $this->id($input['id'] ?? null);
        $menuId = $this->requiredId($input['menu_id'] ?? null, 'menu');
        $parentId = $this->id($input['parent_id'] ?? null);
        $title = trim((string) ($input['title'] ?? ''));
        $type = (string) ($input['link_type'] ?? 'internal');
        $target = trim((string) ($input['target'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new RuntimeException('Item title is required and must not exceed 120 characters.');
        }
        $roleIds = array_values(array_filter(array_map('intval', (array) ($input['role_ids'] ?? [])), static fn (int $value): bool => $value > 0));
        $valid = array_map(static fn (array $role): int => (int) $role['id'], $this->roles());
        if (array_diff($roleIds, $valid) !== []) {
            throw new RuntimeException('One or more selected roles are invalid.');
        }
        return $this->repository->saveItem($id, [
            'menu_id' => $menuId,
            'parent_id' => $parentId,
            'title' => $title,
            'link_type' => $type,
            'target' => $target,
            'url' => $this->urls->resolve($type, $target),
            'sort_order' => max(-10000, min(10000, (int) ($input['sort_order'] ?? 0))),
            'enabled' => ($input['enabled'] ?? null) === '1' ? 1 : 0,
            'new_window' => ($input['new_window'] ?? null) === '1' && $type === 'external' ? 1 : 0,
        ], $roleIds);
    }

    /** @param list<array{id:int,children:list<mixed>}> $itemIds */
    public function reorderItems(int $menuId, array $itemIds): void
    {
        $this->repository->reorderItems($menuId, $itemIds);
    }

    public function deleteMenu(int $id): void { $this->repository->deleteMenu($id); }
    public function deleteItem(int $id): void { $this->repository->deleteItem($id); }

    public function boot(): void
    {
        try {
            $this->database->query('SELECT 1 FROM menus LIMIT 1');
        } catch (\PDOException) {
            return;
        }
        $path = '/' . trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            $this->views->addGlobal('menus', []);
            return;
        }
        $viewerRoles = $this->viewerRoles();
        $viewer = $this->auth->user();
        $menus = [];
        foreach ($this->repository->enabledWithItems() as $menu) {
            $available = $menu['items'];
            foreach ($available as &$item) {
                $itemPath = (string) parse_url((string) $item['url'], PHP_URL_PATH);
                $item['active'] = $item['link_type'] !== 'external' && $itemPath !== '' && $itemPath === $path;
            }
            $items = array_values(array_filter(
                $available,
                fn (array $item): bool => (bool) $item['enabled']
                    && ($item['role_slugs'] === [] || array_intersect($viewerRoles, $item['role_slugs']) !== [])
                    && ($item['link_type'] !== 'module' || $this->moduleAccess->allows((string) $item['target'], $viewer)),
            ));
            $menus[(string) $menu['slug']] = $this->trees->build($items);
        }
        $this->views->addGlobal('menus', $menus);
    }

    private function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Invalid identifier.');
        }
        return (int) $id;
    }

    private function requiredId(mixed $value, string $label): int
    {
        return $this->id($value) ?? throw new RuntimeException("Select a valid {$label}.");
    }

    private function viewerRoles(): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            return ['guest'];
        }
        $statement = $this->database->prepare('SELECT r.slug FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id');
        $statement->execute(['id' => $user['id']]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
