<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['menus','menu_items','menu_item_roles'];
    private const MIGRATION_VALUES = [['permissions','slug','menus.manage'],['menus','slug','primary'],['menu_items','target','/'],['menu_items','target','welcome'],['menu_items','target','/login']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS menus (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY menus_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS menu_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    title VARCHAR(120) NOT NULL,
    link_type VARCHAR(30) NOT NULL DEFAULT 'internal',
    target VARCHAR(2048) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    new_window TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT menu_items_menu_fk FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
    CONSTRAINT menu_items_parent_fk FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    KEY menu_items_tree_index (menu_id, parent_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS menu_item_roles (
    menu_item_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (menu_item_id, role_id),
    CONSTRAINT menu_item_roles_item_fk FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    CONSTRAINT menu_item_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $permission = $database->prepare(
            'INSERT INTO permissions (name, slug, description, module_slug, created_at, updated_at) '
            . 'VALUES (:name, :slug, :description, :module, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), updated_at=UTC_TIMESTAMP()'
        );
        $permission->execute([
            'name' => 'Manage menus',
            'slug' => 'menus.manage',
            'description' => 'Create and arrange navigation menus.',
            'module' => 'menus',
        ]);
        $database->exec(<<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.id, p.id, UTC_TIMESTAMP() FROM roles r CROSS JOIN permissions p
WHERE r.slug='super-administrator' AND p.slug='menus.manage'
SQL);
        $database->exec(<<<'SQL'
INSERT INTO menus (name, slug, description, enabled, created_at, updated_at)
VALUES ('Primary navigation', 'primary', 'Main public navigation.', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),updated_at=updated_at
SQL);
        $menuId = (int) $database->lastInsertId();
        $item = $database->prepare(
            'INSERT INTO menu_items (menu_id, parent_id, title, link_type, target, url, sort_order, enabled, new_window, created_at, updated_at) '
            . 'SELECT :menu,NULL,:title,:type,:target,:url,:sort,1,0,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM DUAL '
            . 'WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE menu_id=:existing_menu AND parent_id IS NULL AND target=:existing_target)'
        );
        foreach ([
            ['Home', 'internal', '/', '/', 10],
            ['Welcome', 'module', 'welcome', '/welcome', 20],
            ['Account', 'internal', '/login', '/login', 30],
        ] as [$title, $type, $target, $url, $sort]) {
            $item->execute([
                'menu' => $menuId,'title' => $title,'type' => $type,'target' => $target,'url' => $url,'sort' => $sort,
                'existing_menu' => $menuId,'existing_target' => $target,
            ]);
        }
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS menu_item_roles');
        $database->exec('DROP TABLE IF EXISTS menu_items');
        $database->exec('DROP TABLE IF EXISTS menus');
        $statement = $database->prepare('DELETE FROM permissions WHERE slug=:slug');
        $statement->execute(['slug' => 'menus.manage']);
    }
};
