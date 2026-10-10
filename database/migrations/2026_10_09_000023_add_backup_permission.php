<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_VALUES = [['permissions', 'slug', 'backup.manage']];

    public function up(PDO $database): void
    {
        $insert = $database->prepare(
            'INSERT INTO permissions (name, slug, description, module_slug, created_at, updated_at) '
            . 'VALUES (:name, :slug, :description, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), updated_at=UTC_TIMESTAMP()'
        );
        $insert->execute(['name' => 'Manage backups', 'slug' => 'backup.manage', 'description' => 'Create and verify backup sets.']);
        $database->exec(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at) "
            . "SELECT r.id, p.id, UTC_TIMESTAMP() FROM roles r CROSS JOIN permissions p "
            . "WHERE r.slug='super-administrator' AND p.slug='backup.manage'"
        );
    }

    public function down(PDO $database): void
    {
        $database->exec(
            "DELETE rp FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE p.slug='backup.manage'"
        );
        $statement = $database->prepare('DELETE FROM permissions WHERE slug=:slug');
        $statement->execute(['slug' => 'backup.manage']);
    }
};
