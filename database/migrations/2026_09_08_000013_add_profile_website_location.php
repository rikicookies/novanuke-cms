<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['user_profiles'=>['website','location']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'user_profiles','website','VARCHAR(255) NULL AFTER bio_format');
        MigrationSchema::addColumn($database,'user_profiles','location','VARCHAR(120) NULL AFTER website');
    }
    public function down(PDO $database): void
    {
        MigrationSchema::dropColumn($database,'user_profiles','location');
        MigrationSchema::dropColumn($database,'user_profiles','website');
    }
};
