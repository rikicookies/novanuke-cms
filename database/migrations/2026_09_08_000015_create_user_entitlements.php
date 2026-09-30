<?php
declare(strict_types=1);
use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['user_entitlements'];
    public function up(PDO $database): void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_entitlements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 entitlement VARCHAR(64) NOT NULL,
 starts_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 granted_by BIGINT UNSIGNED NULL,
 revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY user_entitlements_active_index (user_id,entitlement,revoked_at,expires_at),
 KEY user_entitlements_expiry_index (entitlement,expires_at),
 CONSTRAINT user_entitlements_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT user_entitlements_granter_fk FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
    public function down(PDO $database): void {$database->exec('DROP TABLE IF EXISTS user_entitlements');}
};
