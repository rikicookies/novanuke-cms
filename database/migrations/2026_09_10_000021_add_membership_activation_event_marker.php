<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['user_entitlements'=>['activated_event_at']];
    private const MIGRATION_INDEXES = ['user_entitlements'=>['user_entitlements_activation_event_index']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'user_entitlements','activated_event_at','DATETIME NULL AFTER starts_at');
        $database->exec(
            "UPDATE user_entitlements SET activated_event_at=UTC_TIMESTAMP() "
            . "WHERE starts_at<=UTC_TIMESTAMP()"
        );
        MigrationSchema::createIndex($database,'user_entitlements','user_entitlements_activation_event_index','entitlement,activated_event_at,starts_at,revoked_at,expires_at');
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropIndex($database,'user_entitlements','user_entitlements_activation_event_index');
        MigrationSchema::dropColumn($database,'user_entitlements','activated_event_at');
    }
};
