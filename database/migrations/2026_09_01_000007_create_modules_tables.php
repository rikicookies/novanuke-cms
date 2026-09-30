<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['modules','module_migrations'];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    installed_version VARCHAR(50) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    manifest JSON NOT NULL,
    installed_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_error TEXT NULL,
    UNIQUE KEY modules_slug_unique (slug),
    KEY modules_enabled_index (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS module_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_slug VARCHAR(100) NOT NULL,
    migration VARCHAR(255) NOT NULL,
    batch INT UNSIGNED NOT NULL,
    executed_at DATETIME NOT NULL,
    UNIQUE KEY module_migrations_unique (module_slug, migration),
    KEY module_migrations_module_batch_index (module_slug, batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS module_migrations');
        $database->exec('DROP TABLE IF EXISTS modules');
    }
};
