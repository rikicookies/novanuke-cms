<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['news_articles'=>['audience']];
    private const MIGRATION_INDEXES = ['news_articles'=>['news_articles_audience_index']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'news_articles','audience',"VARCHAR(20) NOT NULL DEFAULT 'public' AFTER status");
        MigrationSchema::createIndex($database,'news_articles','news_articles_audience_index','audience,status,published_at');
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropIndex($database,'news_articles','news_articles_audience_index');
        MigrationSchema::dropColumn($database,'news_articles','audience');
    }
};
