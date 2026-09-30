<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use NovaNuke\Core\Search\SearchProviderInterface;
use NovaNuke\Core\Search\LikePattern;
use NovaNuke\Core\Search\SearchProviderResult;
use NovaNuke\Core\Search\SearchQuery;
use NovaNuke\Core\Search\SearchResultItem;
use PDO;

final class DownloadsSearchProvider implements SearchProviderInterface
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function type(): string { return 'downloads'; }
    public function label(): string { return 'Downloads'; }

    public function search(SearchQuery $query): SearchProviderResult
    {
        $where = "d.deleted_at IS NULL AND d.published_at<=UTC_TIMESTAMP() AND d.status IN ('published','scheduled') "
            . "AND (d.name LIKE :name ESCAPE '=' OR d.description LIKE :description ESCAPE '=' OR d.author_name LIKE :author ESCAPE '=') AND (d.access_type='public' OR (:viewer>0 AND d.access_type='members') "
            . "OR (:vip_viewer>0 AND d.access_type='vip' AND EXISTS (SELECT 1 FROM user_entitlements ue WHERE ue.user_id=:entitlement_viewer AND ue.entitlement='vip' AND ue.starts_at<=UTC_TIMESTAMP() AND (ue.expires_at IS NULL OR ue.expires_at>UTC_TIMESTAMP()) AND ue.revoked_at IS NULL)) "
            . 'OR EXISTS (SELECT 1 FROM download_role_access dra INNER JOIN user_roles ur ON ur.role_id=dra.role_id WHERE dra.download_id=d.id AND ur.user_id=:role_viewer))';
        $like = LikePattern::contains($query->term); $parameters = ['name' => $like, 'description' => $like, 'author' => $like, 'viewer' => $query->userId ?? 0, 'vip_viewer' => $query->userId ?? 0, 'entitlement_viewer' => $query->userId ?? 0, 'role_viewer' => $query->userId ?? 0];
        $count = $this->database->prepare("SELECT COUNT(*) FROM downloads d WHERE {$where}"); $count->execute($parameters); $total = (int) $count->fetchColumn();
        $statement = $this->database->prepare("SELECT d.name,d.slug,d.description,d.published_at FROM downloads d WHERE {$where} ORDER BY d.published_at DESC,d.id DESC LIMIT :limit");
        foreach ($parameters as $name => $value) $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue(':limit', $query->limit, PDO::PARAM_INT); $statement->execute();
        $items = array_map(static fn (array $row): SearchResultItem => new SearchResultItem(
            'downloads', (string) $row['name'], '/downloads/' . $row['slug'], (string) $row['description'], (string) $row['published_at'],
        ), $statement->fetchAll());
        return new SearchProviderResult($items, $total);
    }
}
