<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['news_articles'=>['summary_format','content_format']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'news_articles','summary_format',"VARCHAR(20) NOT NULL DEFAULT 'html' AFTER summary");
        MigrationSchema::addColumn($database,'news_articles','content_format',"VARCHAR(20) NOT NULL DEFAULT 'html' AFTER content");
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropColumn($database,'news_articles','content_format');
        MigrationSchema::dropColumn($database,'news_articles','summary_format');
    }
};
