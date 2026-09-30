<?php

declare(strict_types=1);

namespace Modules\Pages\src;

use NovaNuke\Core\Search\SearchProviderInterface;
use NovaNuke\Core\Search\LikePattern;
use NovaNuke\Core\Search\SearchProviderResult;
use NovaNuke\Core\Search\SearchQuery;
use NovaNuke\Core\Search\SearchResultItem;
use PDO;

final class PagesSearchProvider implements SearchProviderInterface
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function type(): string { return 'pages'; }
    public function label(): string { return 'Pages'; }

    public function search(SearchQuery $query): SearchProviderResult
    {
        $where = "p.deleted_at IS NULL AND p.published_at<=UTC_TIMESTAMP() AND p.status IN ('published','scheduled') "
            . "AND (p.title LIKE :title ESCAPE '=' OR p.content LIKE :content ESCAPE '=') AND (p.access_type='public' OR (:viewer>0 AND p.access_type='members') "
            . "OR (:vip_viewer>0 AND p.access_type='vip' AND EXISTS (SELECT 1 FROM user_entitlements ue WHERE ue.user_id=:entitlement_viewer AND ue.entitlement='vip' AND ue.starts_at<=UTC_TIMESTAMP() AND (ue.expires_at IS NULL OR ue.expires_at>UTC_TIMESTAMP()) AND ue.revoked_at IS NULL)) "
            . 'OR EXISTS (SELECT 1 FROM page_role_access pra INNER JOIN user_roles ur ON ur.role_id=pra.role_id WHERE pra.page_id=p.id AND ur.user_id=:role_viewer))';
        $like = LikePattern::contains($query->term); $parameters = ['title' => $like, 'content' => $like, 'viewer' => $query->userId ?? 0, 'vip_viewer' => $query->userId ?? 0, 'entitlement_viewer' => $query->userId ?? 0, 'role_viewer' => $query->userId ?? 0];
        $count = $this->database->prepare("SELECT COUNT(*) FROM pages p WHERE {$where}"); $count->execute($parameters); $total = (int) $count->fetchColumn();
        $statement = $this->database->prepare("SELECT p.title,p.slug,p.content,p.published_at FROM pages p WHERE {$where} ORDER BY p.published_at DESC,p.id DESC LIMIT :limit");
        foreach ($parameters as $name => $value) $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue(':limit', $query->limit, PDO::PARAM_INT); $statement->execute();
        $items = array_map(static fn (array $row): SearchResultItem => new SearchResultItem(
            'pages', (string) $row['title'], '/pages/' . $row['slug'], (string) $row['content'], (string) $row['published_at'],
        ), $statement->fetchAll());
        return new SearchProviderResult($items, $total);
    }
}
