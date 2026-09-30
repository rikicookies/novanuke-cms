<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['downloads'=>['description_format','requirements_format']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'downloads','description_format',"VARCHAR(20) NOT NULL DEFAULT 'html' AFTER description");
        MigrationSchema::addColumn($database,'downloads','requirements_format',"VARCHAR(20) NOT NULL DEFAULT 'html' AFTER requirements");
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropColumn($database,'downloads','requirements_format');
        MigrationSchema::dropColumn($database,'downloads','description_format');
    }
};
