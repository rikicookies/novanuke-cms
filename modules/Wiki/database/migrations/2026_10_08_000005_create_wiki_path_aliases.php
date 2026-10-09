<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration
{
    use VerifiesMigrationState;

    private const MIGRATION_TABLES = ['wiki_path_aliases'];

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS wiki_path_aliases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    historical_path VARCHAR(311) NOT NULL,
    wiki_page_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY wiki_path_aliases_path_unique (historical_path),
    KEY wiki_path_aliases_page_index (wiki_page_id),
    CONSTRAINT wiki_path_aliases_page_fk FOREIGN KEY (wiki_page_id)
        REFERENCES wiki_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wiki_path_aliases');
    }

};
