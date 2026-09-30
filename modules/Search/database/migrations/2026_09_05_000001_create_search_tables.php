<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['search_queries'];
    private const MIGRATION_VALUES = [['settings','key','search.log_terms']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS search_queries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(100) NOT NULL,
    search_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
    last_searched_at DATETIME NOT NULL,
    UNIQUE KEY search_queries_term_unique (term),
    KEY search_queries_popular_index (search_count, last_searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $statement = $database->prepare("INSERT INTO settings (`key`,`value`,`type`,group_name,created_at,updated_at) VALUES ('search.log_terms','0','boolean','search',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `key`=`key`");
        $statement->execute();
    }

    public function down(PDO $database): void
    {
        $database->prepare("DELETE FROM settings WHERE `key`='search.log_terms'")->execute();
        $database->exec('DROP TABLE IF EXISTS search_queries');
    }
};
