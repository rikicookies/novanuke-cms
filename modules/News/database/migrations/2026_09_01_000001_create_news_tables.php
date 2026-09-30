<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['news_categories','news_topics','news_articles','news_tags','news_article_tags'];
    private const MIGRATION_VALUES = [['news_categories','slug','general'],['news_topics','slug','announcements']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS news_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY news_categories_slug_unique (slug),
    CONSTRAINT news_categories_parent_fk FOREIGN KEY (parent_id) REFERENCES news_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS news_topics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    image_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY news_topics_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS news_articles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    topic_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    summary TEXT NULL,
    content MEDIUMTEXT NOT NULL,
    featured_image VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
    seo_title VARCHAR(200) NULL,
    seo_description VARCHAR(320) NULL,
    view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY news_articles_slug_unique (slug),
    KEY news_articles_public_index (status, published_at, id),
    KEY news_articles_category_index (category_id, status, published_at),
    KEY news_articles_topic_index (topic_id, status, published_at),
    CONSTRAINT news_articles_author_fk FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT news_articles_category_fk FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE SET NULL,
    CONSTRAINT news_articles_topic_fk FOREIGN KEY (topic_id) REFERENCES news_topics(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS news_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY news_tags_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS news_article_tags (
    article_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, tag_id),
    CONSTRAINT news_article_tags_article_fk FOREIGN KEY (article_id) REFERENCES news_articles(id) ON DELETE CASCADE,
    CONSTRAINT news_article_tags_tag_fk FOREIGN KEY (tag_id) REFERENCES news_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $category = $database->prepare('INSERT INTO news_categories (name,slug,description,created_at,updated_at) VALUES (:name,:slug,:description,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)');
        $category->execute(['name' => 'General', 'slug' => 'general', 'description' => 'General news and announcements.']);
        $topic = $database->prepare('INSERT INTO news_topics (name,slug,description,created_at,updated_at) VALUES (:name,:slug,:description,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)');
        $topic->execute(['name' => 'Announcements', 'slug' => 'announcements', 'description' => 'Official site announcements.']);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS news_article_tags');
        $database->exec('DROP TABLE IF EXISTS news_tags');
        $database->exec('DROP TABLE IF EXISTS news_articles');
        $database->exec('DROP TABLE IF EXISTS news_topics');
        $database->exec('DROP TABLE IF EXISTS news_categories');
    }
};
