<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['user_profiles'=>['bio_format']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'user_profiles','bio_format',"VARCHAR(20) NOT NULL DEFAULT 'markdown' AFTER bio");
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropColumn($database,'user_profiles','bio_format');
    }
};
