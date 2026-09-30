<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['user_entitlements'=>['plan_key','source','note']];
    private const MIGRATION_INDEXES = ['user_entitlements'=>['user_entitlements_plan_index']];
    public function up(PDO $database): void
    {
        $database->exec("ALTER TABLE user_entitlements MODIFY expires_at DATETIME NULL");
        MigrationSchema::addColumn($database,'user_entitlements','plan_key','VARCHAR(64) NULL AFTER entitlement');
        MigrationSchema::addColumn($database,'user_entitlements','source',"VARCHAR(32) NOT NULL DEFAULT 'manual' AFTER plan_key");
        MigrationSchema::addColumn($database,'user_entitlements','note','VARCHAR(255) NULL AFTER source');
        $database->exec("UPDATE user_entitlements SET plan_key='vip-custom' WHERE entitlement='vip' AND plan_key IS NULL");
        MigrationSchema::createIndex($database,'user_entitlements','user_entitlements_plan_index','plan_key,revoked_at,expires_at');
    }

    public function down(PDO $database): void
    {
        $database->exec("UPDATE user_entitlements SET expires_at='9999-12-31 23:59:59' WHERE expires_at IS NULL");
        MigrationSchema::dropIndex($database,'user_entitlements','user_entitlements_plan_index');
        MigrationSchema::dropColumn($database,'user_entitlements','note');
        MigrationSchema::dropColumn($database,'user_entitlements','source');
        MigrationSchema::dropColumn($database,'user_entitlements','plan_key');
        $database->exec("ALTER TABLE user_entitlements MODIFY expires_at DATETIME NOT NULL");
    }

    public function isApplied(PDO $database): bool
    {
        return MigrationSchema::columnsExist($database,'user_entitlements',['plan_key','source','note'])
            && MigrationSchema::indexExists($database,'user_entitlements','user_entitlements_plan_index')
            && MigrationSchema::columnIsNullable($database,'user_entitlements','expires_at');
    }

    public function isRolledBack(PDO $database): bool
    {
        return MigrationSchema::columnsAbsent($database,'user_entitlements',['plan_key','source','note'])
            && ! MigrationSchema::indexExists($database,'user_entitlements','user_entitlements_plan_index')
            && ! MigrationSchema::columnIsNullable($database,'user_entitlements','expires_at');
    }
};
