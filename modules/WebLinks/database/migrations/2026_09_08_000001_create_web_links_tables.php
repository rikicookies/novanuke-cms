<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['web_link_categories','web_links','web_link_visits','web_link_reports'];
    private const MIGRATION_VALUES = [['web_link_categories','slug','general']];
    public function up(PDO $database):void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS web_link_categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL,
 description VARCHAR(500) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY web_link_categories_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS web_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id BIGINT UNSIGNED NULL,
 submitted_by BIGINT UNSIGNED NULL, title VARCHAR(200) NOT NULL, slug VARCHAR(200) NOT NULL,
 url VARCHAR(2048) NOT NULL, description TEXT NOT NULL, image_path VARCHAR(255) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending', is_featured TINYINT(1) NOT NULL DEFAULT 0,
 visit_count BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL,
 UNIQUE KEY web_links_slug_unique (slug), KEY web_links_public_index (status,is_featured,created_at,id),
 KEY web_links_category_index (category_id,status,created_at),
 CONSTRAINT web_links_category_fk FOREIGN KEY (category_id) REFERENCES web_link_categories(id) ON DELETE SET NULL,
 CONSTRAINT web_links_submitter_fk FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS web_link_visits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, link_id BIGINT UNSIGNED NOT NULL,
 visitor_key CHAR(64) NOT NULL, visited_at DATETIME NOT NULL,
 KEY web_link_visits_lookup_index (link_id,visitor_key,visited_at),
 CONSTRAINT web_link_visits_link_fk FOREIGN KEY (link_id) REFERENCES web_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS web_link_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, link_id BIGINT UNSIGNED NOT NULL,
 reporter_user_id BIGINT UNSIGNED NULL, reporter_key CHAR(64) NOT NULL, reason VARCHAR(500) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL, resolved_at DATETIME NULL,
 UNIQUE KEY web_link_reports_once_unique (link_id,reporter_key), KEY web_link_reports_status_index (status,created_at),
 CONSTRAINT web_link_reports_link_fk FOREIGN KEY (link_id) REFERENCES web_links(id) ON DELETE CASCADE,
 CONSTRAINT web_link_reports_user_fk FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->prepare('INSERT INTO web_link_categories (name,slug,description,created_at,updated_at) VALUES (:name,:slug,:description,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)')->execute(['name'=>'General','slug'=>'general','description'=>'General recommended links.']);
    }
    public function down(PDO $database):void{$database->exec('DROP TABLE IF EXISTS web_link_reports');$database->exec('DROP TABLE IF EXISTS web_link_visits');$database->exec('DROP TABLE IF EXISTS web_links');$database->exec('DROP TABLE IF EXISTS web_link_categories');}
};
