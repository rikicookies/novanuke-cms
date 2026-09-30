<?php

declare(strict_types=1);

namespace NovaNuke\Core\Menus;

use PDO;
use RuntimeException;

final class MenuRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $menus = $this->database->query('SELECT * FROM menus ORDER BY name, id')->fetchAll();
        foreach ($menus as &$menu) {
            $menu['items'] = $this->items((int) $menu['id'], true);
        }
        return $menus;
    }

    /** @return list<array<string, mixed>> */
    public function enabled(): array
    {
        return $this->database->query('SELECT * FROM menus WHERE enabled=1 ORDER BY id')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function enabledWithItems():array
    {
        $menus=$this->enabled();if($menus===[])return[];
        $items=$this->database->query('SELECT i.* FROM menu_items i INNER JOIN menus m ON m.id=i.menu_id WHERE m.enabled=1 ORDER BY i.menu_id,i.sort_order,i.id')->fetchAll();
        $roles=$this->database->query('SELECT mir.menu_item_id,r.slug FROM menu_item_roles mir INNER JOIN roles r ON r.id=mir.role_id INNER JOIN menu_items i ON i.id=mir.menu_item_id INNER JOIN menus m ON m.id=i.menu_id WHERE m.enabled=1 ORDER BY mir.menu_item_id,r.slug')->fetchAll();
        $roleMap=[];foreach($roles as$row)$roleMap[(int)$row['menu_item_id']][]=(string)$row['slug'];$itemMap=[];foreach($items as$item){$item['role_slugs']=$roleMap[(int)$item['id']]??[];$itemMap[(int)$item['menu_id']][]=$item;}foreach($menus as&$menu)$menu['items']=$itemMap[(int)$menu['id']]??[];return$menus;
    }

    /** @return list<array<string, mixed>> */
    public function items(int $menuId, bool $withRoleIds = false): array
    {
        $statement = $this->database->prepare('SELECT * FROM menu_items WHERE menu_id=:menu ORDER BY sort_order, id');
        $statement->execute(['menu' => $menuId]);
        $items = $statement->fetchAll();
        $roleMap = $this->roleMap($menuId, ! $withRoleIds);
        foreach ($items as &$item) {
            $item[$withRoleIds ? 'role_ids' : 'role_slugs'] = $roleMap[(int) $item['id']] ?? [];
        }
        return $items;
    }

    /** @param array<string, mixed> $data */
    public function saveMenu(?int $id, array $data): int
    {
        if ($id === null) {
            $statement = $this->database->prepare(
                'INSERT INTO menus (name,slug,description,enabled,created_at,updated_at) '
                . 'VALUES (:name,:slug,:description,:enabled,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
        } else {
            $this->assertMenu($id);
            $statement = $this->database->prepare(
                'UPDATE menus SET name=:name,slug=:slug,description=:description,enabled=:enabled,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            );
            $data['id'] = $id;
        }
        try {
            $statement->execute($data);
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new RuntimeException('The menu slug is already in use.', 0, $error);
            }
            throw $error;
        }
        return $id ?? (int) $this->database->lastInsertId();
    }

    /** @param array<string, mixed> $data @param list<int> $roleIds */
    public function saveItem(?int $id, array $data, array $roleIds): int
    {
        $this->assertMenu((int) $data['menu_id']);
        if ($id !== null) {
            $existing = $this->item($id);
            if ($existing === null || (int) $existing['menu_id'] !== (int) $data['menu_id']) {
                throw new RuntimeException('Menu item not found.');
            }
        }
        $parentId = $data['parent_id'];
        if ($parentId !== null) {
            $parent = $this->item((int) $parentId);
            if ($parent === null || (int) $parent['menu_id'] !== (int) $data['menu_id']) {
                throw new RuntimeException('The parent item must belong to the same menu.');
            }
            if ($id !== null && ($id === (int) $parentId || $this->isDescendant($id, (int) $parentId))) {
                throw new RuntimeException('A menu item cannot be placed below itself or one of its children.');
            }
        }
        $this->database->beginTransaction();
        try {
            if ($id === null) {
                $sql = 'INSERT INTO menu_items (menu_id,parent_id,title,link_type,target,url,sort_order,enabled,new_window,created_at,updated_at) '
                    . 'VALUES (:menu_id,:parent_id,:title,:link_type,:target,:url,:sort_order,:enabled,:new_window,UTC_TIMESTAMP(),UTC_TIMESTAMP())';
            } else {
                $sql = 'UPDATE menu_items SET menu_id=:menu_id,parent_id=:parent_id,title=:title,link_type=:link_type,target=:target,url=:url,'
                    . 'sort_order=:sort_order,enabled=:enabled,new_window=:new_window,updated_at=UTC_TIMESTAMP() WHERE id=:id';
                $data['id'] = $id;
            }
            $statement = $this->database->prepare($sql);
            $statement->execute($data);
            $itemId = $id ?? (int) $this->database->lastInsertId();
            $this->database->prepare('DELETE FROM menu_item_roles WHERE menu_item_id=:id')->execute(['id' => $itemId]);
            $insert = $this->database->prepare('INSERT INTO menu_item_roles (menu_item_id,role_id) VALUES (:item,:role)');
            foreach (array_unique($roleIds) as $roleId) {
                $insert->execute(['item' => $itemId, 'role' => $roleId]);
            }
            $this->database->commit();
            return $itemId;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }


    /** @param list<array{id:int,children:list<mixed>}> $tree */
    public function reorderItems(int $menuId, array $tree): void
    {
        $this->assertMenu($menuId);

        $statement = $this->database->prepare(
            'SELECT id,parent_id FROM menu_items WHERE menu_id=:menu ORDER BY id'
        );
        $statement->execute(['menu' => $menuId]);
        $rows = $statement->fetchAll();

        $expected = [];
        $parents = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $expected[$id] = true;
            $parents[$id] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        $orders = [];
        $seen = [];
        $walk = function (array $nodes, ?int $parentId) use (&$walk, &$orders, &$seen, $expected, $parents): void {
            foreach ($nodes as $index => $node) {
                if (! is_array($node)) {
                    throw new RuntimeException('Invalid menu order payload.');
                }
                $id = filter_var($node['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || ! isset($expected[(int) $id]) || isset($seen[(int) $id])) {
                    throw new RuntimeException('Invalid menu order payload.');
                }
                if (($parents[(int) $id] ?? null) !== $parentId) {
                    throw new RuntimeException('Menu items can only be reordered within their existing parent level.');
                }

                $seen[(int) $id] = true;
                $orders[] = ['id' => (int) $id, 'sort_order' => ($index + 1) * 10];
                $children = $node['children'] ?? [];
                if (! is_array($children)) {
                    throw new RuntimeException('Invalid menu order payload.');
                }
                $walk($children, (int) $id);
            }
        };
        $walk($tree, null);

        if (count($seen) !== count($expected)) {
            throw new RuntimeException('The submitted menu order does not match the menu items.');
        }

        $this->database->beginTransaction();
        try {
            $update = $this->database->prepare(
                'UPDATE menu_items SET sort_order=:sort_order,updated_at=UTC_TIMESTAMP() '
                . 'WHERE id=:id AND menu_id=:menu'
            );
            foreach ($orders as $order) {
                $update->execute([
                    'sort_order' => $order['sort_order'],
                    'id' => $order['id'],
                    'menu' => $menuId,
                ]);
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function deleteMenu(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM menus WHERE id=:id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Menu not found.');
        }
    }

    public function deleteItem(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM menu_items WHERE id=:id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Menu item not found.');
        }
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function roles(): array
    {
        return $this->database->query('SELECT id,name,slug FROM roles ORDER BY id')->fetchAll();
    }

    /** @return array<string,mixed>|null */
    private function item(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM menu_items WHERE id=:id');
        $statement->execute(['id' => $id]);
        $item = $statement->fetch();
        return is_array($item) ? $item : null;
    }

    private function assertMenu(int $id): void
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM menus WHERE id=:id');
        $statement->execute(['id' => $id]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Menu not found.');
        }
    }

    private function isDescendant(int $itemId, int $possibleDescendant): bool
    {
        $current = $this->item($possibleDescendant);
        $seen = [];
        while ($current !== null && $current['parent_id'] !== null) {
            $parent = (int) $current['parent_id'];
            if ($parent === $itemId) {
                return true;
            }
            if (isset($seen[$parent])) {
                return true;
            }
            $seen[$parent] = true;
            $current = $this->item($parent);
        }
        return false;
    }

    /** @return array<int, list<int|string>> */
    private function roleMap(int $menuId, bool $slugs): array
    {
        $field = $slugs ? 'r.slug AS role_value' : 'r.id AS role_value';
        $statement = $this->database->prepare(
            "SELECT mir.menu_item_id, {$field} FROM menu_item_roles mir "
            . 'INNER JOIN menu_items mi ON mi.id=mir.menu_item_id '
            . 'INNER JOIN roles r ON r.id=mir.role_id WHERE mi.menu_id=:menu'
        );
        $statement->execute(['menu' => $menuId]);
        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $map[(int) $row['menu_item_id']][] = $slugs ? (string) $row['role_value'] : (int) $row['role_value'];
        }
        return $map;
    }
}
