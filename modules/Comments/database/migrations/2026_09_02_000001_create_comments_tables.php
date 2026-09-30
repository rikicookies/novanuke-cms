<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['comments','comment_reports'];
    private const MIGRATION_VALUES = [['settings','key','comments.guests_allowed'],['settings','key','comments.moderation_required']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(100) NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    guest_name VARCHAR(100) NULL,
    body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ip_hash CHAR(64) NOT NULL,
    edited_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT comments_parent_fk FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE,
    CONSTRAINT comments_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY comments_content_index (content_type, content_id, status, created_at),
    KEY comments_parent_index (parent_id),
    KEY comments_moderation_index (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS comment_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id BIGINT UNSIGNED NOT NULL,
    reporter_user_id BIGINT UNSIGNED NULL,
    reporter_key CHAR(64) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    CONSTRAINT comment_reports_comment_fk FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    CONSTRAINT comment_reports_user_fk FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY comment_reports_once_unique (comment_id, reporter_key),
    KEY comment_reports_status_index (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $setting = $database->prepare(
            'INSERT INTO settings (`key`,`value`,`type`,group_name,created_at,updated_at) VALUES (:key,:value,:type,:group,UTC_TIMESTAMP(),UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE `key`=`key`'
        );
        foreach ([
            ['comments.guests_allowed', '0', 'boolean'],
            ['comments.moderation_required', '1', 'boolean'],
        ] as [$key, $value, $type]) {
            $setting->execute(['key' => $key, 'value' => $value, 'type' => $type, 'group' => 'comments']);
        }
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS comment_reports');
        $database->exec('DROP TABLE IF EXISTS comments');
        $statement = $database->prepare("DELETE FROM settings WHERE `key` IN ('comments.guests_allowed','comments.moderation_required')");
        $statement->execute();
    }
};
