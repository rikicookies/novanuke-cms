<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['themes'];
    private const MIGRATION_VALUES = [['settings','key','theme.active']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS themes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    installed_version VARCHAR(50) NOT NULL,
    manifest JSON NOT NULL,
    settings JSON NULL,
    installed_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY themes_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $statement = $database->prepare(
            'INSERT INTO settings (`key`, `value`, `type`, group_name, created_at, updated_at) '
            . 'VALUES (:key, :value, :type, :group_name, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE `key` = `key`'
        );
        $statement->execute([
            'key' => 'theme.active',
            'value' => '',
            'type' => 'string',
            'group_name' => 'appearance',
        ]);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS themes');
        $statement = $database->prepare('DELETE FROM settings WHERE `key` = :key');
        $statement->execute(['key' => 'theme.active']);
    }
};
