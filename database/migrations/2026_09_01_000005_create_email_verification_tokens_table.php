<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['email_verification_tokens'];
    private const MIGRATION_VALUES = [['settings','key','users.email_verification_required']];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY email_verification_token_hash_unique (token_hash),
    KEY email_verification_user_index (user_id),
    KEY email_verification_expiration_index (expires_at),
    CONSTRAINT email_verification_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $statement = $database->prepare(
            'INSERT INTO settings (`key`, `value`, `type`, group_name, created_at, updated_at) '
            . 'VALUES (:key, :value, :type, :group_name, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE `key` = `key`'
        );
        $statement->execute([
            'key' => 'users.email_verification_required',
            'value' => '1',
            'type' => 'boolean',
            'group_name' => 'users',
        ]);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS email_verification_tokens');
        $statement = $database->prepare('DELETE FROM settings WHERE `key` = :key');
        $statement->execute(['key' => 'users.email_verification_required']);
    }
};
