<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['settings'];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(190) NOT NULL,
    `value` LONGTEXT NULL,
    `type` VARCHAR(32) NOT NULL DEFAULT 'string',
    group_name VARCHAR(100) NOT NULL DEFAULT 'general',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY settings_key_unique (`key`),
    KEY settings_group_index (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS settings');
    }
};
