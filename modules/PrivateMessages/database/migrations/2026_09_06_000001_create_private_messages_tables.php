<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['private_conversations','private_conversation_participants','private_messages','private_message_blocks','private_message_reports'];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS private_conversations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, subject VARCHAR(200) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL, last_message_at DATETIME NOT NULL, created_at DATETIME NOT NULL,
 KEY private_conversations_recent_index (last_message_at,id),
 CONSTRAINT private_conversations_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS private_conversation_participants (
 conversation_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 last_read_message_id BIGINT UNSIGNED NULL, deleted_at DATETIME NULL,
 PRIMARY KEY (conversation_id,user_id), KEY private_participants_user_index (user_id,deleted_at),
 CONSTRAINT private_participants_conversation_fk FOREIGN KEY (conversation_id) REFERENCES private_conversations(id) ON DELETE CASCADE,
 CONSTRAINT private_participants_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS private_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conversation_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL,
 KEY private_messages_conversation_index (conversation_id,id), KEY private_messages_sender_index (sender_id,created_at),
 CONSTRAINT private_messages_conversation_fk FOREIGN KEY (conversation_id) REFERENCES private_conversations(id) ON DELETE CASCADE,
 CONSTRAINT private_messages_sender_fk FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS private_message_blocks (
 blocker_user_id BIGINT UNSIGNED NOT NULL, blocked_user_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 PRIMARY KEY (blocker_user_id,blocked_user_id),
 CONSTRAINT private_blocks_blocker_fk FOREIGN KEY (blocker_user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT private_blocks_blocked_fk FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS private_message_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message_id BIGINT UNSIGNED NOT NULL,
 reporter_user_id BIGINT UNSIGNED NOT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open',
 created_at DATETIME NOT NULL, resolved_at DATETIME NULL,
 UNIQUE KEY private_reports_once_unique (message_id,reporter_user_id), KEY private_reports_status_index (status,created_at),
 CONSTRAINT private_reports_message_fk FOREIGN KEY (message_id) REFERENCES private_messages(id) ON DELETE CASCADE,
 CONSTRAINT private_reports_reporter_fk FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS private_message_reports');
        $database->exec('DROP TABLE IF EXISTS private_message_blocks');
        $database->exec('DROP TABLE IF EXISTS private_messages');
        $database->exec('DROP TABLE IF EXISTS private_conversation_participants');
        $database->exec('DROP TABLE IF EXISTS private_conversations');
    }
};
