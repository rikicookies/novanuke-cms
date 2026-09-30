<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['wiki_pages'=>['comments_enabled'],'wiki_page_revisions'=>['comments_enabled']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'wiki_pages','comments_enabled','TINYINT(1) NOT NULL DEFAULT 0 AFTER audience');
        MigrationSchema::addColumn($database,'wiki_page_revisions','comments_enabled','TINYINT(1) NOT NULL DEFAULT 0 AFTER audience');
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropColumn($database,'wiki_page_revisions','comments_enabled');
        MigrationSchema::dropColumn($database,'wiki_pages','comments_enabled');
    }
};
