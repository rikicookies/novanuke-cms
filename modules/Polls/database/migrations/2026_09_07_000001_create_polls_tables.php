<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['polls','poll_options','poll_votes','poll_vote_choices'];
    private const MIGRATION_VALUES = [['blocks','slug','polls-active-poll']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS polls (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question VARCHAR(300) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft', allow_multiple TINYINT(1) NOT NULL DEFAULT 0,
 max_selections TINYINT UNSIGNED NOT NULL DEFAULT 1, starts_at DATETIME NULL, ends_at DATETIME NULL,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 KEY polls_active_index (status,starts_at,ends_at,id),
 CONSTRAINT polls_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS poll_options (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, poll_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(200) NOT NULL, sort_order INT NOT NULL DEFAULT 0,
 KEY poll_options_order_index (poll_id,sort_order,id),
 CONSTRAINT poll_options_poll_fk FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS poll_votes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, poll_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NULL, voter_key CHAR(64) NOT NULL, voted_at DATETIME NOT NULL,
 UNIQUE KEY poll_votes_voter_unique (poll_id,voter_key), KEY poll_votes_date_index (poll_id,voted_at),
 CONSTRAINT poll_votes_poll_fk FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
 CONSTRAINT poll_votes_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS poll_vote_choices (
 vote_id BIGINT UNSIGNED NOT NULL, option_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (vote_id,option_id), KEY poll_choices_option_index (option_id),
 CONSTRAINT poll_choices_vote_fk FOREIGN KEY (vote_id) REFERENCES poll_votes(id) ON DELETE CASCADE,
 CONSTRAINT poll_choices_option_fk FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec("INSERT IGNORE INTO blocks (title,slug,type,position,content,configuration,visibility_mode,page_patterns,module_slugs,enabled,show_title,sort_order,starts_at,ends_at,created_by,created_at,updated_at) VALUES ('Active poll','polls-active-poll','polls-active','right-sidebar',NULL,JSON_OBJECT(),'all',JSON_ARRAY(),JSON_ARRAY(),1,1,20,NULL,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    }

    public function down(PDO $database): void
    {
        $database->exec("DELETE FROM blocks WHERE type='polls-active'");
        $database->exec('DROP TABLE IF EXISTS poll_vote_choices');
        $database->exec('DROP TABLE IF EXISTS poll_votes');
        $database->exec('DROP TABLE IF EXISTS poll_options');
        $database->exec('DROP TABLE IF EXISTS polls');
    }
};
