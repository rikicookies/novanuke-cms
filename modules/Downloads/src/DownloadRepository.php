<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use NovaNuke\Core\Membership\MembershipManagerInterface;
use PDO;
use RuntimeException;

final class DownloadRepository
{
    private readonly int $perPage;

    public function __construct(
        private readonly PDO $database,
        private readonly MembershipManagerInterface $memberships,
        int $perPage = 10,
    )
    {
        $this->perPage = max(5, min(100, $perPage));
    }

    public function categories(): array { return $this->database->query('SELECT c.*,p.name AS parent_name FROM download_categories c LEFT JOIN download_categories p ON p.id=c.parent_id ORDER BY COALESCE(p.name,c.name),c.parent_id,c.name')->fetchAll(); }
    public function roles(): array { return $this->database->query("SELECT id,name,slug FROM roles WHERE slug<>'guest' ORDER BY name")->fetchAll(); }

    /** @return array<int,string> */
    public function storedNames(): array
    {
        $names = $this->database->query("SELECT stored_name FROM downloads WHERE source_type='local' AND stored_name IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map('strval', $names)));
    }

    public function adminDownloads(): array
    {
        return $this->database->query('SELECT d.id,d.name,d.slug,d.source_type,d.status,d.access_type,d.download_count,d.published_at,c.name AS category_name FROM downloads d LEFT JOIN download_categories c ON c.id=d.category_id WHERE d.deleted_at IS NULL ORDER BY d.updated_at DESC,d.id DESC')->fetchAll();
    }

    public function download(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM downloads WHERE id=:id AND deleted_at IS NULL'); $statement->execute(['id' => $id]);
        $download = $statement->fetch(); if (! is_array($download)) return null;
        $download['role_ids'] = $this->roleIds($id); return $download;
    }

    /** @param array<string,mixed> $data */
    public function save(?int $id, array $data, int $actorId): int
    {
        $roles = $data['role_ids']; unset($data['role_ids']);
        $this->assertCategory($data['category_id']); $this->assertRoles($roles);
        $this->database->beginTransaction();
        try {
            if ($id === null) {
                $sql = 'INSERT INTO downloads (category_id,created_by,name,slug,description,description_format,version,author_name,source_type,stored_name,original_name,external_url,file_size,mime_type,image_path,requirements,requirements_format,license_name,status,access_type,is_featured,published_at,created_at,updated_at) VALUES (:category_id,:created_by,:name,:slug,:description,:description_format,:version,:author_name,:source_type,:stored_name,:original_name,:external_url,:file_size,:mime_type,:image_path,:requirements,:requirements_format,:license_name,:status,:access_type,:is_featured,:published_at,UTC_TIMESTAMP(),UTC_TIMESTAMP())';
                $data['created_by'] = $actorId;
            } else {
                if ($this->download($id) === null) throw new RuntimeException('Download not found.');
                $sql = 'UPDATE downloads SET category_id=:category_id,name=:name,slug=:slug,description=:description,description_format=:description_format,version=:version,author_name=:author_name,source_type=:source_type,stored_name=:stored_name,original_name=:original_name,external_url=:external_url,file_size=:file_size,mime_type=:mime_type,image_path=:image_path,requirements=:requirements,requirements_format=:requirements_format,license_name=:license_name,status=:status,access_type=:access_type,is_featured=:is_featured,published_at=:published_at,updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL';
                $data['id'] = $id;
            }
            $statement = $this->database->prepare($sql); $statement->execute($data);
            $downloadId = $id ?? (int) $this->database->lastInsertId(); $this->syncRoles($downloadId, $roles);
            $this->database->commit(); return $downloadId;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') throw new RuntimeException('The download slug is already in use.', 0, $error);
            throw $error;
        }
    }

    public function saveCategory(array $data): int
    {
        $this->assertCategory($data['parent_id']);
        try {
            $statement = $this->database->prepare('INSERT INTO download_categories (parent_id,name,slug,description,created_at,updated_at) VALUES (:parent_id,:name,:slug,:description,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $statement->execute($data); return (int) $this->database->lastInsertId();
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') throw new RuntimeException('The category slug is already in use.', 0, $error);
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('UPDATE downloads SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND deleted_at IS NULL');
        $statement->execute(['id' => $id]); if ($statement->rowCount() !== 1) throw new RuntimeException('Download not found.');
    }

    public function publicDownload(string $slug): ?array
    {
        $statement = $this->database->prepare("SELECT d.*,c.name AS category_name,c.slug AS category_slug FROM downloads d LEFT JOIN download_categories c ON c.id=d.category_id WHERE d.slug=:slug AND d.deleted_at IS NULL AND d.published_at<=UTC_TIMESTAMP() AND d.status IN ('published','scheduled') LIMIT 1");
        $statement->execute(['slug' => $slug]); $download = $statement->fetch(); return is_array($download) ? $download : null;
    }

    public function publicDownloadById(int $id): ?array
    {
        $statement = $this->database->prepare("SELECT * FROM downloads WHERE id=:id AND deleted_at IS NULL AND published_at<=UTC_TIMESTAMP() AND status IN ('published','scheduled') LIMIT 1");
        $statement->execute(['id' => $id]); $download = $statement->fetch(); return is_array($download) ? $download : null;
    }

    public function catalog(int $page, ?string $category, string $search, string $order, ?int $userId): array
    {
        $where = "d.deleted_at IS NULL AND d.published_at<=UTC_TIMESTAMP() AND d.status IN ('published','scheduled') AND (d.access_type='public' OR (:viewer>0 AND d.access_type='members') OR (:vip_viewer>0 AND d.access_type='vip' AND EXISTS (SELECT 1 FROM user_entitlements ue WHERE ue.user_id=:entitlement_viewer AND ue.entitlement='vip' AND ue.starts_at<=UTC_TIMESTAMP() AND (ue.expires_at IS NULL OR ue.expires_at>UTC_TIMESTAMP()) AND ue.revoked_at IS NULL)) OR EXISTS (SELECT 1 FROM download_role_access dra INNER JOIN user_roles ur ON ur.role_id=dra.role_id WHERE dra.download_id=d.id AND ur.user_id=:role_viewer))";
        $parameters = ['viewer' => $userId ?? 0, 'vip_viewer' => $userId ?? 0, 'entitlement_viewer' => $userId ?? 0, 'role_viewer' => $userId ?? 0];
        if ($category !== null) { $where .= ' AND c.slug=:category'; $parameters['category'] = $category; }
        if ($search !== '') { $where .= ' AND (d.name LIKE :search OR d.description LIKE :search_description OR d.author_name LIKE :search_author)'; $term = '%' . $search . '%'; $parameters += ['search' => $term, 'search_description' => $term, 'search_author' => $term]; }
        $count = $this->database->prepare("SELECT COUNT(*) FROM downloads d LEFT JOIN download_categories c ON c.id=d.category_id WHERE {$where}"); $count->execute($parameters);
        $total = (int) $count->fetchColumn(); $perPage = $this->perPage; $pages = max(1, (int) ceil($total / $perPage)); $page = min(max(1, $page), $pages);
        $orders = ['new' => 'd.published_at DESC,d.id DESC', 'popular' => 'd.download_count DESC,d.published_at DESC', 'name' => 'd.name,d.id'];
        $sql = "SELECT d.id,d.name,d.slug,d.description,d.description_format,d.version,d.author_name,d.image_path,d.is_featured,d.download_count,d.published_at,d.access_type,c.name AS category_name,c.slug AS category_slug FROM downloads d LEFT JOIN download_categories c ON c.id=d.category_id WHERE {$where} ORDER BY " . ($orders[$order] ?? $orders['new']) . ' LIMIT :limit OFFSET :offset';
        $statement = $this->database->prepare($sql);
        foreach ($parameters as $key => $value) $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT); $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT); $statement->execute();
        return ['items' => $statement->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function canView(array $download, ?int $userId): bool
    {
        if ($download['access_type'] === 'public') return true; if ($userId === null) return false; if ($download['access_type'] === 'members') return true;
        if ($download['access_type'] === 'vip') return $this->memberships->isVip($userId);
        $statement = $this->database->prepare('SELECT COUNT(*) FROM download_role_access dra INNER JOIN user_roles ur ON ur.role_id=dra.role_id WHERE dra.download_id=:download AND ur.user_id=:user');
        $statement->execute(['download' => $download['id'], 'user' => $userId]); return (int) $statement->fetchColumn() > 0;
    }

    public function recordDownload(int $id, string $visitorKey): bool
    {
        $this->database->beginTransaction();
        try {
            $this->database->exec('DELETE FROM download_events WHERE downloaded_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)');
            $check = $this->database->prepare('SELECT COUNT(*) FROM download_events WHERE download_id=:id AND visitor_key=:visitor AND downloaded_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)');
            $check->execute(['id' => $id, 'visitor' => $visitorKey]); $counted = (int) $check->fetchColumn() === 0;
            if ($counted) {
                $this->database->prepare('INSERT INTO download_events (download_id,visitor_key,downloaded_at) VALUES (:id,:visitor,UTC_TIMESTAMP())')->execute(['id' => $id, 'visitor' => $visitorKey]);
                $this->database->prepare('UPDATE downloads SET download_count=download_count+1 WHERE id=:id')->execute(['id' => $id]);
            }
            $this->database->commit(); return $counted;
        } catch (\Throwable $error) { if ($this->database->inTransaction()) $this->database->rollBack(); throw $error; }
    }

    public function report(int $downloadId, ?int $userId, string $key, string $reason): int
    {
        try {
            $statement = $this->database->prepare("INSERT INTO download_reports (download_id,reporter_user_id,reporter_key,reason,status,created_at) VALUES (:download,:user,:reporter,:reason,'open',UTC_TIMESTAMP())");
            $statement->execute(['download' => $downloadId, 'user' => $userId, 'reporter' => $key, 'reason' => $reason]); return (int) $this->database->lastInsertId();
        } catch (\PDOException $error) { if ($error->getCode() === '23000') throw new RuntimeException('You already reported this download.', 0, $error); throw $error; }
    }

    public function openReports(): array { return $this->database->query("SELECT r.id,r.download_id,r.reason,r.created_at,d.name,COALESCE(u.username,'Guest') AS reporter FROM download_reports r INNER JOIN downloads d ON d.id=r.download_id LEFT JOIN users u ON u.id=r.reporter_user_id WHERE r.status='open' ORDER BY r.created_at DESC")->fetchAll(); }
    public function resolveReport(int $id): void { $statement = $this->database->prepare("UPDATE download_reports SET status='resolved',resolved_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'"); $statement->execute(['id' => $id]); if ($statement->rowCount() !== 1) throw new RuntimeException('Open report not found.'); }

    private function assertCategory(?int $id): void { if ($id === null) return; $statement = $this->database->prepare('SELECT COUNT(*) FROM download_categories WHERE id=:id'); $statement->execute(['id' => $id]); if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Selected category does not exist.'); }
    private function assertRoles(array $ids): void { $valid = array_map(static fn (array $role): int => (int) $role['id'], $this->roles()); if (array_diff($ids, $valid) !== []) throw new RuntimeException('One or more selected roles are invalid.'); }
    private function syncRoles(int $id, array $roles): void { $this->database->prepare('DELETE FROM download_role_access WHERE download_id=:id')->execute(['id' => $id]); $statement = $this->database->prepare('INSERT INTO download_role_access (download_id,role_id) VALUES (:download,:role)'); foreach ($roles as $role) $statement->execute(['download' => $id, 'role' => $role]); }
    private function roleIds(int $id): array { $statement = $this->database->prepare('SELECT role_id FROM download_role_access WHERE download_id=:id'); $statement->execute(['id' => $id]); return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)); }
}
