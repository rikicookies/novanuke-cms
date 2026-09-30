<?php

declare(strict_types=1);

use NovaNuke\Core\Database\RecoverableMigration;
use NovaNuke\Core\Database\VerifiesMigrationState;

return new class implements RecoverableMigration {
    use VerifiesMigrationState;
    private const MIGRATION_TABLES = ['statistics_daily'];
    private const MIGRATION_VALUES = [['settings','key','statistics.collection_enabled'],['settings','key','statistics.public_enabled']];
    public function up(PDO $database):void
    {
        $database->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS statistics_daily (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, statistic_date DATE NOT NULL,
 section VARCHAR(50) NOT NULL, referrer_host VARCHAR(190) NOT NULL DEFAULT 'direct',
 browser_type VARCHAR(30) NOT NULL DEFAULT 'other', device_type VARCHAR(30) NOT NULL DEFAULT 'other',
 view_count BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL,
 UNIQUE KEY statistics_daily_dimension_unique (statistic_date,section,referrer_host,browser_type,device_type),
 KEY statistics_daily_date_index (statistic_date), KEY statistics_daily_section_index (section,statistic_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $setting=$database->prepare('INSERT INTO settings (`key`,`value`,`type`,group_name,created_at,updated_at) VALUES (:key,:value,:type,:group,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `key`=`key`');
        foreach([['statistics.collection_enabled','1'],['statistics.public_enabled','0']] as[$key,$value])$setting->execute(['key'=>$key,'value'=>$value,'type'=>'boolean','group'=>'statistics']);
        $database->exec("INSERT IGNORE INTO blocks (title,slug,type,position,content,configuration,visibility_mode,page_patterns,module_slugs,enabled,show_title,sort_order,starts_at,ends_at,created_by,created_at,updated_at) VALUES ('Site statistics','statistics-summary','statistics-summary','left-sidebar',NULL,JSON_OBJECT(),'all',JSON_ARRAY(),JSON_ARRAY(),0,1,30,NULL,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    }
    public function down(PDO $database):void{$database->exec("DELETE FROM blocks WHERE type='statistics-summary'");$database->prepare("DELETE FROM settings WHERE `key` IN ('statistics.collection_enabled','statistics.public_enabled')")->execute();$database->exec('DROP TABLE IF EXISTS statistics_daily');}
};
