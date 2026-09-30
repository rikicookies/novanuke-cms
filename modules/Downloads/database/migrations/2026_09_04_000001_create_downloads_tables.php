<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['download_categories','downloads','download_role_access','download_events','download_reports'];
    private const MIGRATION_VALUES = [['download_categories','slug','general']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS download_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY download_categories_slug_unique (slug),
    CONSTRAINT download_categories_parent_fk FOREIGN KEY (parent_id) REFERENCES download_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS downloads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    version VARCHAR(50) NULL,
    author_name VARCHAR(150) NULL,
    source_type VARCHAR(20) NOT NULL,
    stored_name VARCHAR(100) NULL,
    original_name VARCHAR(255) NULL,
    external_url VARCHAR(2048) NULL,
    file_size BIGINT UNSIGNED NULL,
    mime_type VARCHAR(150) NULL,
    image_path VARCHAR(255) NULL,
    requirements TEXT NULL,
    license_name VARCHAR(150) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    access_type VARCHAR(20) NOT NULL DEFAULT 'public',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY downloads_slug_unique (slug),
    KEY downloads_public_index (status, published_at, id),
    KEY downloads_category_index (category_id, status, published_at),
    KEY downloads_popular_index (download_count, published_at),
    CONSTRAINT downloads_category_fk FOREIGN KEY (category_id) REFERENCES download_categories(id) ON DELETE SET NULL,
    CONSTRAINT downloads_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS download_role_access (
    download_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (download_id, role_id),
    CONSTRAINT download_role_access_download_fk FOREIGN KEY (download_id) REFERENCES downloads(id) ON DELETE CASCADE,
    CONSTRAINT download_role_access_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS download_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    download_id BIGINT UNSIGNED NOT NULL,
    visitor_key CHAR(64) NOT NULL,
    downloaded_at DATETIME NOT NULL,
    KEY download_events_lookup_index (download_id, visitor_key, downloaded_at),
    CONSTRAINT download_events_download_fk FOREIGN KEY (download_id) REFERENCES downloads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS download_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    download_id BIGINT UNSIGNED NOT NULL,
    reporter_user_id BIGINT UNSIGNED NULL,
    reporter_key CHAR(64) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    UNIQUE KEY download_reports_once_unique (download_id, reporter_key),
    KEY download_reports_status_index (status, created_at),
    CONSTRAINT download_reports_download_fk FOREIGN KEY (download_id) REFERENCES downloads(id) ON DELETE CASCADE,
    CONSTRAINT download_reports_user_fk FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $category = $database->prepare('INSERT INTO download_categories (name,slug,description,created_at,updated_at) VALUES (:name,:slug,:description,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)');
        $category->execute(['name' => 'General', 'slug' => 'general', 'description' => 'General downloads.']);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS download_reports');
        $database->exec('DROP TABLE IF EXISTS download_events');
        $database->exec('DROP TABLE IF EXISTS download_role_access');
        $database->exec('DROP TABLE IF EXISTS downloads');
        $database->exec('DROP TABLE IF EXISTS download_categories');
    }
};
