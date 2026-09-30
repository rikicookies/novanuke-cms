<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['blocks','block_roles'];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS blocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'html',
    position VARCHAR(100) NOT NULL,
    content MEDIUMTEXT NULL,
    configuration JSON NULL,
    visibility_mode VARCHAR(20) NOT NULL DEFAULT 'all',
    page_patterns JSON NULL,
    module_slugs JSON NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    show_title TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY blocks_slug_unique (slug),
    KEY blocks_render_index (enabled, position, sort_order),
    KEY blocks_schedule_index (starts_at, ends_at),
    CONSTRAINT blocks_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS block_roles (
    block_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (block_id, role_id),
    CONSTRAINT block_roles_block_fk FOREIGN KEY (block_id) REFERENCES blocks(id) ON DELETE CASCADE,
    CONSTRAINT block_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS block_roles');
        $database->exec('DROP TABLE IF EXISTS blocks');
    }
};
