<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['pages','page_role_access'];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    template VARCHAR(50) NOT NULL DEFAULT 'default',
    access_type VARCHAR(20) NOT NULL DEFAULT 'public',
    comments_enabled TINYINT(1) NOT NULL DEFAULT 0,
    show_in_directory TINYINT(1) NOT NULL DEFAULT 0,
    menu_title VARCHAR(120) NULL,
    seo_title VARCHAR(200) NULL,
    seo_description VARCHAR(320) NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY pages_slug_unique (slug),
    KEY pages_public_index (status, published_at, id),
    KEY pages_parent_index (parent_id),
    CONSTRAINT pages_parent_fk FOREIGN KEY (parent_id) REFERENCES pages(id) ON DELETE SET NULL,
    CONSTRAINT pages_author_fk FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS page_role_access (
    page_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (page_id, role_id),
    CONSTRAINT page_role_access_page_fk FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT page_role_access_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $database): void
    {
        try { $database->exec("DELETE FROM comments WHERE content_type='pages'"); } catch (PDOException) {}
        $database->exec('DROP TABLE IF EXISTS page_role_access');
        $database->exec('DROP TABLE IF EXISTS pages');
    }
};
