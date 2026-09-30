<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['web_links'=>['description_format']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'web_links','description_format',"VARCHAR(20) NOT NULL DEFAULT 'html' AFTER description");
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropColumn($database,'web_links','description_format');
    }
};
