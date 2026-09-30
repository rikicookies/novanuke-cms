<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_VALUES = [['blocks','slug','polls-active-poll']];
    public function up(PDO $database): void
    {
        $database->exec(
            "INSERT IGNORE INTO blocks (title,slug,type,position,content,configuration,visibility_mode,page_patterns,module_slugs,enabled,show_title,sort_order,starts_at,ends_at,created_by,created_at,updated_at) "
            . "VALUES ('Active poll','polls-active-poll','polls-active','right-sidebar',NULL,JSON_OBJECT(),'all',JSON_ARRAY(),JSON_ARRAY(),0,1,20,NULL,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
    }

    public function down(PDO $database): void
    {
        $database->exec("DELETE FROM blocks WHERE slug='polls-active-poll' AND type='polls-active'");
    }
};
