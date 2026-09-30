<?php
declare(strict_types=1);
use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;
use NovaNuke\Core\Database\MigrationSchema;
return new class implements RecoverableMigration {
    use VerifiesMigrationState;
 private const MIGRATION_COLUMNS = ['modules'=>['audience']];
 public function up(PDO $database):void{MigrationSchema::addColumn($database,'modules','audience',"VARCHAR(20) NOT NULL DEFAULT 'public' AFTER enabled");}
 public function down(PDO $database):void{MigrationSchema::dropColumn($database,'modules','audience');}
};
