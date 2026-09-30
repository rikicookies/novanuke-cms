<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['rate_limits','activity_logs'];
    private const MIGRATION_VALUES = [['permissions','slug','admin.access']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_hash CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_ends_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY rate_limits_key_unique (key_hash),
    KEY rate_limits_window_index (window_ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    subject_type VARCHAR(120) NULL,
    subject_id VARCHAR(120) NULL,
    context JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT activity_logs_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY activity_logs_actor_index (actor_user_id, created_at),
    KEY activity_logs_action_index (action, created_at),
    KEY activity_logs_created_index (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $permissions = [
            ['Access administration', 'admin.access', 'Open the administrative panel.'],
            ['View users', 'users.view', 'View user accounts and profiles.'],
            ['Manage users', 'users.manage', 'Suspend, reactivate and update users.'],
            ['Assign user roles', 'users.assign_roles', 'Assign roles to user accounts.'],
            ['View roles', 'roles.view', 'View roles and their permissions.'],
            ['Manage roles', 'roles.manage', 'Change role permission assignments.'],
            ['Manage settings', 'settings.manage', 'Change system configuration.'],
            ['View activity logs', 'logs.view', 'View administrative activity history.'],
            ['Manage modules', 'modules.manage', 'Install, enable and configure modules.'],
            ['Manage themes', 'themes.manage', 'Install and select themes.'],
            ['Manage blocks', 'blocks.manage', 'Configure site blocks.'],
            ['Publish news', 'news.publish', 'Publish news articles.'],
            ['Edit news', 'news.edit', 'Create and edit news articles.'],
            ['Moderate comments', 'comments.moderate', 'Moderate user comments.'],
            ['Manage pages', 'pages.manage', 'Create and edit pages.'],
            ['Manage downloads', 'downloads.manage', 'Manage downloadable content.'],
        ];
        $insert = $database->prepare(
            'INSERT INTO permissions (name, slug, description, module_slug, created_at, updated_at) '
            . 'VALUES (:name, :slug, :description, :module_slug, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), updated_at = UTC_TIMESTAMP()'
        );
        foreach ($permissions as [$name, $slug, $description]) {
            $insert->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'module_slug' => str_contains($slug, '.') ? explode('.', $slug, 2)[0] : null,
            ]);
        }

        $database->exec(<<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.id, p.id, UTC_TIMESTAMP()
FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super-administrator'
SQL);
        $database->exec(<<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT r.id, p.id, UTC_TIMESTAMP()
FROM roles r INNER JOIN permissions p
    ON p.slug IN ('admin.access', 'users.view', 'users.manage', 'users.assign_roles', 'roles.view', 'settings.manage', 'logs.view')
WHERE r.slug = 'administrator'
SQL);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS activity_logs');
        $database->exec('DROP TABLE IF EXISTS rate_limits');
        $slugs = [
            'admin.access', 'users.view', 'users.manage', 'users.assign_roles', 'roles.view', 'roles.manage',
            'settings.manage', 'logs.view', 'modules.manage', 'themes.manage', 'blocks.manage', 'news.publish',
            'news.edit', 'comments.moderate', 'pages.manage', 'downloads.manage',
        ];
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $statement = $database->prepare("DELETE FROM permissions WHERE slug IN ({$placeholders})");
        $statement->execute($slugs);
    }
};
