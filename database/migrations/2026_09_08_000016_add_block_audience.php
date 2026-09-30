<?php
declare(strict_types=1);
use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;
return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_COLUMNS = ['blocks'=>['audience']];
    public function up(PDO $database):void{MigrationSchema::addColumn($database,'blocks','audience',"VARCHAR(20) NOT NULL DEFAULT 'public' AFTER visibility_mode");}
    public function down(PDO $database):void{MigrationSchema::dropColumn($database,'blocks','audience');}
};
