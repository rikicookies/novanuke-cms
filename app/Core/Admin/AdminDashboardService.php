<?php

declare(strict_types=1);

namespace NovaNuke\Core\Admin;

use NovaNuke\Core\Modules\ModuleManager;
use PDO;

final class AdminDashboardService
{
    public function __construct(
        private readonly PDO $database,
        private readonly ModuleManager $modules,
        private readonly DashboardContentMerger $contentMerger = new DashboardContentMerger(),
        private readonly DashboardPrioritySorter $prioritySorter = new DashboardPrioritySorter(),
    ) {
    }

    /** @param array<string,bool> $permissions
     *  @return array<string,mixed>
     */
    public function build(array $permissions): array
    {
        $inventory = $this->modules->inventory();
        $enabled = static fn (string $slug): bool => (bool) ($inventory[$slug]['enabled'] ?? false);
        $cards = [];
        $recentUsers = [];
        $recentActivity = [];
        $contentGroups = [];
        $attention = [];
        $quickActions = [];

        if ($permissions['users.view'] ?? false) {
            $cards[] = ['label' => 'admin.dashboard.registered_users', 'value' => $this->count("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL"), 'url' => '/admin/users'];
            $cards[] = ['label' => 'admin.dashboard.active_users', 'value' => $this->count("SELECT COUNT(*) FROM users WHERE status = 'active' AND deleted_at IS NULL"), 'url' => '/admin/users'];
            $recentUsers = $this->database->query(
                'SELECT id, username, status, created_at FROM users WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 5'
            )->fetchAll();
            if ($permissions['users.create'] ?? false) {
                $quickActions[] = ['label' => 'admin.dashboard.create_user', 'url' => '/admin/users/create'];
            }
        }
        if (($permissions['memberships.manage'] ?? false) && $this->tableExists('user_entitlements')) {
            $activeVip=$this->count(
                "SELECT COUNT(DISTINCT user_id) FROM user_entitlements WHERE entitlement='vip' "
                . "AND revoked_at IS NULL AND starts_at<=UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())"
            );
            $expiringVip=$this->count(
                "SELECT COUNT(DISTINCT user_id) FROM user_entitlements WHERE entitlement='vip' "
                . "AND revoked_at IS NULL AND starts_at<=UTC_TIMESTAMP() AND expires_at IS NOT NULL "
                . "AND expires_at>UTC_TIMESTAMP() AND expires_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY)"
            );
            $lifetimeVip=$this->count(
                "SELECT COUNT(DISTINCT user_id) FROM user_entitlements WHERE entitlement='vip' "
                . "AND revoked_at IS NULL AND starts_at<=UTC_TIMESTAMP() AND expires_at IS NULL"
            );
            $scheduledVip=$this->count(
                "SELECT COUNT(DISTINCT user_id) FROM user_entitlements WHERE entitlement='vip' "
                . "AND revoked_at IS NULL AND starts_at>UTC_TIMESTAMP()"
            );
            $cards[]=['label'=>'admin.dashboard.active_vip','value'=>$activeVip,'url'=>'/admin/memberships?status=active'];
            $cards[]=['label'=>'admin.dashboard.lifetime_vip','value'=>$lifetimeVip,'url'=>'/admin/memberships?status=lifetime'];
            $cards[]=['label'=>'admin.dashboard.scheduled_vip','value'=>$scheduledVip,'url'=>'/admin/memberships?status=scheduled'];
            $quickActions[]=['label'=>'admin.dashboard.manage_memberships','url'=>'/admin/memberships'];
            if($expiringVip>0){
                $attention[]=$this->attention('admin.dashboard.expiring_vip',$expiringVip,'/admin/memberships?status=expiring',80);
            }
        }
        if (($permissions['news.edit'] ?? false) && $enabled('news') && $this->tableExists('news_articles')) {
            $published = $this->count("SELECT COUNT(*) FROM news_articles WHERE status = 'published' AND deleted_at IS NULL");
            $unpublished = $this->count("SELECT COUNT(*) FROM news_articles WHERE status <> 'published' AND deleted_at IS NULL");
            $cards[] = ['label' => 'admin.dashboard.published_news', 'value' => $published, 'url' => '/admin/news'];
            $contentGroups[] = $this->content('news_articles', 'title', 'modules.navigation.news', '/admin/news/%d/edit');
            $quickActions[] = ['label' => 'admin.dashboard.create_news', 'url' => '/admin/news/new'];
            if ($unpublished > 0) {
                $attention[] = $this->attention('admin.dashboard.unpublished_news', $unpublished, '/admin/news', 60);
            }
        }
        if (($permissions['pages.edit'] ?? false) && $enabled('pages') && $this->tableExists('pages')) {
            $published = $this->count("SELECT COUNT(*) FROM pages WHERE status = 'published' AND deleted_at IS NULL");
            $unpublished = $this->count("SELECT COUNT(*) FROM pages WHERE status <> 'published' AND deleted_at IS NULL");
            $cards[] = ['label' => 'admin.dashboard.published_pages', 'value' => $published, 'url' => '/admin/pages'];
            $contentGroups[] = $this->content('pages', 'title', 'admin.dashboard.page_type', '/admin/pages/%d/edit');
            $quickActions[] = ['label' => 'admin.dashboard.create_page', 'url' => '/admin/pages/new'];
            if ($unpublished > 0) {
                $attention[] = $this->attention('admin.dashboard.unpublished_pages', $unpublished, '/admin/pages', 60);
            }
        }
        if (($permissions['downloads.manage'] ?? false) && $enabled('downloads') && $this->tableExists('downloads')) {
            $published = $this->count("SELECT COUNT(*) FROM downloads WHERE status = 'published' AND deleted_at IS NULL");
            $unpublished = $this->count("SELECT COUNT(*) FROM downloads WHERE status <> 'published' AND deleted_at IS NULL");
            $cards[] = ['label' => 'admin.dashboard.published_downloads', 'value' => $published, 'url' => '/admin/downloads'];
            $contentGroups[] = $this->content('downloads', 'name', 'admin.dashboard.download_type', '/admin/downloads/%d/edit');
            $quickActions[] = ['label' => 'admin.dashboard.create_download', 'url' => '/admin/downloads/new'];
            if ($unpublished > 0) {
                $attention[] = $this->attention('admin.dashboard.unpublished_downloads', $unpublished, '/admin/downloads', 60);
            }
            if ($this->tableExists('download_reports')) {
                $reports = $this->count("SELECT COUNT(*) FROM download_reports WHERE status = 'open'");
                if ($reports > 0) {
                    $attention[] = $this->attention('admin.dashboard.broken_downloads', $reports, '/admin/downloads', 85);
                }
            }
        }
        if (($permissions['comments.moderate'] ?? false) && $enabled('comments') && $this->tableExists('comments')) {
            $pending = $this->count("SELECT COUNT(*) FROM comments WHERE status = 'pending'");
            $cards[] = ['label' => 'admin.dashboard.pending_comments', 'value' => $pending, 'url' => '/admin/comments'];
            if ($pending > 0) {
                $attention[] = $this->attention('admin.dashboard.comments_moderation', $pending, '/admin/comments', 90);
            }
            if ($this->tableExists('comment_reports')) {
                $reports = $this->count("SELECT COUNT(*) FROM comment_reports WHERE status = 'open'");
                if ($reports > 0) {
                    $attention[] = $this->attention('admin.dashboard.reported_comments', $reports, '/admin/comments', 95);
                }
            }
        }
        if (($permissions['web-links.manage'] ?? false) && $enabled('web-links') && $this->tableExists('web_links')) {
            $quickActions[] = ['label' => 'admin.dashboard.create_web_link', 'url' => '/admin/web-links/new'];
            if ($this->tableExists('web_link_reports')) {
                $reports = $this->count("SELECT COUNT(*) FROM web_link_reports WHERE status = 'open'");
                if ($reports > 0) {
                    $attention[] = $this->attention('admin.dashboard.broken_web_links', $reports, '/admin/web-links', 85);
                }
            }
        }
        if (($permissions['private-messages.moderate'] ?? false) && $enabled('private-messages') && $this->tableExists('private_message_reports')) {
            $reports = $this->count("SELECT COUNT(*) FROM private_message_reports WHERE status = 'open'");
            if ($reports > 0) {
                $attention[] = $this->attention('admin.dashboard.message_reports', $reports, '/admin/private-messages', 95);
            }
        }
        if ($permissions['logs.view'] ?? false) {
            $recentActivity = $this->database->query(
                'SELECT al.id, al.action, al.subject_type, al.created_at, u.username AS actor_username '
                . 'FROM activity_logs al LEFT JOIN users u ON u.id = al.actor_user_id ORDER BY al.id DESC LIMIT 8'
            )->fetchAll();
        }

        $moduleStatus = null;
        if ($permissions['modules.manage'] ?? false) {
            $issues = count(array_filter($inventory, static fn (array $module): bool => ! $module['compatible'] || $module['last_error'] !== null));
            $moduleStatus = [
                'detected' => count($inventory),
                'enabled' => count(array_filter($inventory, static fn (array $module): bool => $module['enabled'])),
                'issues' => $issues,
            ];
            if ($issues > 0) {
                $attention[] = $this->attention('admin.dashboard.module_issues', $issues, '/admin/modules', 100);
            }
        }

        return [
            'cards' => $cards,
            'recent_users' => $recentUsers,
            'recent_activity' => $recentActivity,
            'recent_content' => $this->contentMerger->merge($contentGroups),
            'module_status' => $moduleStatus,
            'attention' => $this->prioritySorter->sort($attention),
            'quick_actions' => $quickActions,
        ];
    }

    private function count(string $sql): int
    {
        return (int) $this->database->query($sql)->fetchColumn();
    }

    /** @return array{label:string,count:int,url:string,priority:int} */
    private function attention(string $label, int $count, string $url, int $priority): array
    {
        return compact('label', 'count', 'url', 'priority');
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> */
    private function content(string $table, string $titleColumn, string $type, string $url): array
    {
        $rows = $this->database->query(
            "SELECT id, `{$titleColumn}` AS title, status, created_at FROM `{$table}` WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 5"
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['type'] = $type;
            $row['admin_url'] = sprintf($url, (int) $row['id']);
        }
        unset($row);
        return $rows;
    }
}
