<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS wiki_page_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wiki_page_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    namespace VARCHAR(190) NOT NULL DEFAULT '',
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    audience VARCHAR(20) NOT NULL,
    published_at DATETIME NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY wiki_revisions_number_unique (wiki_page_id, revision_number),
    KEY wiki_revisions_created_index (wiki_page_id, created_at),
    CONSTRAINT wiki_revisions_page_fk FOREIGN KEY (wiki_page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
    CONSTRAINT wiki_revisions_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
INSERT IGNORE INTO wiki_page_revisions
    (wiki_page_id,revision_number,namespace,slug,title,content,status,audience,published_at,actor_id,created_at)
SELECT id,1,namespace,slug,title,content,status,audience,published_at,author_id,updated_at
FROM wiki_pages
SQL);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS wiki_page_revisions');
    }

    public function isApplied(PDO $database): bool
    {
        if (! MigrationSchema::tableExists($database, 'wiki_page_revisions')) return false;
        return (int) $database->query(
            'SELECT COUNT(*) FROM wiki_pages p LEFT JOIN wiki_page_revisions r '
            . 'ON r.wiki_page_id=p.id AND r.revision_number=1 WHERE r.id IS NULL'
        )->fetchColumn() === 0;
    }

    public function isRolledBack(PDO $database): bool
    {
        return ! MigrationSchema::tableExists($database, 'wiki_page_revisions');
    }
};
