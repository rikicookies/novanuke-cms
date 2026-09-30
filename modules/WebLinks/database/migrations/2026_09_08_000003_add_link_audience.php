<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['web_links'=>['audience']];
    private const MIGRATION_INDEXES = ['web_links'=>['web_links_audience_index']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'web_links','audience',"VARCHAR(20) NOT NULL DEFAULT 'public' AFTER status");
        MigrationSchema::createIndex($database,'web_links','web_links_audience_index','audience,status,created_at');
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropIndex($database,'web_links','web_links_audience_index');
        MigrationSchema::dropColumn($database,'web_links','audience');
    }
};
