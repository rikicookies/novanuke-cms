<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['comment_reactions'];
    public function up(PDO $database): void
    {
        $database->exec("CREATE TABLE IF NOT EXISTS comment_reactions (comment_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,reaction VARCHAR(20) NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(comment_id,user_id),KEY comment_reactions_count_index(comment_id,reaction),CONSTRAINT comment_reactions_comment_fk FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE,CONSTRAINT comment_reactions_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(PDO $database): void { $database->exec('DROP TABLE IF EXISTS comment_reactions'); }
};
