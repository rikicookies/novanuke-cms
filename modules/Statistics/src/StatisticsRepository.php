<?php

declare(strict_types=1);

namespace Modules\Statistics\src;

use PDO;

final class StatisticsRepository
{
    public function __construct(private readonly PDO $database){}
    /** @param array{section:string,referrer:string,browser:string,device:string} $d */
    public function record(array $d):void
    {
        $date=gmdate('Y-m-d');$host=$d['referrer'];if(!in_array($host,['direct','other'],true)){$exists=$this->database->prepare('SELECT COUNT(*) FROM statistics_daily WHERE statistic_date=:date AND referrer_host=:host');$exists->execute(compact('date','host'));if((int)$exists->fetchColumn()===0){$count=$this->database->prepare("SELECT COUNT(DISTINCT referrer_host) FROM statistics_daily WHERE statistic_date=:date AND referrer_host NOT IN ('direct','other')");$count->execute(compact('date'));if((int)$count->fetchColumn()>=100)$host='other';}}
        $s=$this->database->prepare('INSERT INTO statistics_daily (statistic_date,section,referrer_host,browser_type,device_type,view_count,updated_at) VALUES (:date,:section,:host,:browser,:device,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE view_count=view_count+1,updated_at=UTC_TIMESTAMP()');$s->execute(['date'=>$date,'section'=>$d['section'],'host'=>$host,'browser'=>$d['browser'],'device'=>$d['device']]);
    }
    public function summary():array{return['registered_users'=>$this->scalar("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL"),'active_users'=>$this->scalar("SELECT COUNT(*) FROM users WHERE status='active' AND deleted_at IS NULL AND last_login_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)"),'published_news'=>$this->optional("SELECT COUNT(*) FROM news_articles WHERE deleted_at IS NULL AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled')"),'approved_comments'=>$this->optional("SELECT COUNT(*) FROM comments WHERE status='approved'"),'downloads'=>$this->optional('SELECT COALESCE(SUM(download_count),0) FROM downloads WHERE deleted_at IS NULL'),'published_pages'=>$this->optional("SELECT COUNT(*) FROM pages WHERE deleted_at IS NULL AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled')"),'link_visits'=>$this->optional("SELECT COALESCE(SUM(visit_count),0) FROM web_links WHERE deleted_at IS NULL AND status='published'"),'poll_votes'=>$this->optional('SELECT COUNT(*) FROM poll_votes'),'views_30_days'=>$this->scalar('SELECT COALESCE(SUM(view_count),0) FROM statistics_daily WHERE statistic_date>=DATE_SUB(UTC_DATE(),INTERVAL 29 DAY)')];}
    public function trends():array{return$this->rows('SELECT statistic_date,SUM(view_count) AS views FROM statistics_daily WHERE statistic_date>=DATE_SUB(UTC_DATE(),INTERVAL 13 DAY) GROUP BY statistic_date ORDER BY statistic_date');}
    public function sections():array{return$this->rows('SELECT section,SUM(view_count) AS views FROM statistics_daily WHERE statistic_date>=DATE_SUB(UTC_DATE(),INTERVAL 29 DAY) GROUP BY section ORDER BY views DESC');}
    public function referrers():array{return$this->rows("SELECT referrer_host,SUM(view_count) AS views FROM statistics_daily WHERE statistic_date>=DATE_SUB(UTC_DATE(),INTERVAL 29 DAY) GROUP BY referrer_host ORDER BY views DESC LIMIT 20");}
    public function devices():array{return$this->rows('SELECT device_type,SUM(view_count) AS views FROM statistics_daily WHERE statistic_date>=DATE_SUB(UTC_DATE(),INTERVAL 29 DAY) GROUP BY device_type ORDER BY views DESC');}
    public function browsers():array{return$this->rows('SELECT browser_type,SUM(view_count) AS views FROM statistics_daily WHERE statistic_date>=DATE_SUB(UTC_DATE(),INTERVAL 29 DAY) GROUP BY browser_type ORDER BY views DESC');}
    public function topContent():array{return['news'=>$this->optionalRows("SELECT title,slug,view_count AS views FROM news_articles WHERE deleted_at IS NULL AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled') ORDER BY view_count DESC LIMIT 10"),'downloads'=>$this->optionalRows("SELECT name AS title,slug,download_count AS views FROM downloads WHERE deleted_at IS NULL AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled') ORDER BY download_count DESC LIMIT 10"),'links'=>$this->optionalRows("SELECT title,slug,visit_count AS views FROM web_links WHERE deleted_at IS NULL AND status='published' ORDER BY visit_count DESC LIMIT 10")];}
    public function recentActivity():array{return$this->optionalRows('SELECT al.action,al.subject_type,al.subject_id,al.created_at,u.username FROM activity_logs al LEFT JOIN users u ON u.id=al.actor_user_id ORDER BY al.id DESC LIMIT 20');}
    public function prune(bool $dryRun):int{$where='statistic_date<DATE_SUB(UTC_DATE(),INTERVAL 395 DAY)';return$dryRun?(int)$this->database->query("SELECT COUNT(*) FROM statistics_daily WHERE {$where}")->fetchColumn():(int)$this->database->exec("DELETE FROM statistics_daily WHERE {$where}");}
    private function scalar(string $sql):int{return(int)$this->database->query($sql)->fetchColumn();}private function rows(string $sql):array{return$this->database->query($sql)->fetchAll();}private function optional(string $sql):int{try{return$this->scalar($sql);}catch(\PDOException){return 0;}}private function optionalRows(string $sql):array{try{return$this->rows($sql);}catch(\PDOException){return[];}}
}
