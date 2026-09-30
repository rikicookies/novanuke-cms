<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['user_entitlements'=>['expired_event_at']];
    private const MIGRATION_INDEXES = ['user_entitlements'=>['user_entitlements_expiration_event_index']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'user_entitlements','expired_event_at','DATETIME NULL AFTER revoked_at');
        MigrationSchema::createIndex($database,'user_entitlements','user_entitlements_expiration_event_index','entitlement,expired_event_at,expires_at,revoked_at');
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropIndex($database,'user_entitlements','user_entitlements_expiration_event_index');
        MigrationSchema::dropColumn($database,'user_entitlements','expired_event_at');
    }
};
