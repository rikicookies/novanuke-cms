<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['users'=>['must_change_password']];
    public function up(PDO $database): void
    {
        MigrationSchema::addColumn($database,'users','must_change_password','TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash');
    }

    public function down(PDO $database): void
    {
        MigrationSchema::dropColumn($database,'users','must_change_password');
    }
};
