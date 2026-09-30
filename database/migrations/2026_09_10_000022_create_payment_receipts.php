<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['payment_receipts'];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider VARCHAR(32) NOT NULL,
 external_reference VARCHAR(191) NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 plan_key VARCHAR(64) NOT NULL,
 amount_minor BIGINT UNSIGNED NOT NULL,
 currency CHAR(3) NOT NULL,
 processed_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY payment_receipts_provider_reference_unique (provider,external_reference),
 KEY payment_receipts_user_index (user_id,processed_at),
 CONSTRAINT payment_receipts_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS payment_receipts');
    }
};
