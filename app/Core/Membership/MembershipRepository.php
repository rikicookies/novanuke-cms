<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

use PDO;

final class MembershipRepository
{
    public function __construct(private readonly PDO $database) {}

    /** @return array{items:list<array<string,mixed>>,counts:array<string,int>} */
    public function overview(string $filter = 'active', string $search = ''): array
    {
        $filter = in_array($filter, ['all','active','expiring','lifetime','scheduled','inactive','never'], true) ? $filter : 'active';
        $search = mb_substr(trim($search), 0, 100);
        $latest = "(SELECT x.id FROM user_entitlements x WHERE x.user_id=u.id AND x.entitlement='vip' "
            . "ORDER BY (x.revoked_at IS NULL AND x.starts_at<=UTC_TIMESTAMP() AND (x.expires_at IS NULL OR x.expires_at>UTC_TIMESTAMP())) DESC, "
            . "(x.revoked_at IS NULL AND x.starts_at>UTC_TIMESTAMP()) DESC, x.id DESC LIMIT 1)";
        $active = "ue.id IS NOT NULL AND ue.revoked_at IS NULL AND ue.starts_at<=UTC_TIMESTAMP() AND (ue.expires_at IS NULL OR ue.expires_at>UTC_TIMESTAMP())";
        $scheduled = "ue.id IS NOT NULL AND ue.revoked_at IS NULL AND ue.starts_at>UTC_TIMESTAMP()";

        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $where[] = "(u.username LIKE :search ESCAPE '=' OR u.email LIKE :search_email ESCAPE '=')";
            $term = '%' . strtr($search, ['='=>'==','%'=>'=%','_'=>'=_']) . '%';
            $params['search'] = $term;
            $params['search_email'] = $term;
        }

        $filterSql = match ($filter) {
            'active' => " AND {$active}",
            'expiring' => " AND {$active} AND ue.expires_at IS NOT NULL AND ue.expires_at<=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
            'lifetime' => " AND {$active} AND ue.expires_at IS NULL",
            'scheduled' => " AND {$scheduled}",
            'inactive' => " AND ue.id IS NOT NULL AND NOT ({$active}) AND NOT ({$scheduled})",
            'never' => " AND ue.id IS NULL",
            default => '',
        };

        $sql = "SELECT u.id,u.username,u.email,u.status,ue.plan_key,ue.source,ue.note,ue.starts_at,ue.expires_at,ue.revoked_at,"
            . "CASE WHEN {$active} THEN 1 ELSE 0 END AS membership_active "
            . "FROM users u LEFT JOIN user_entitlements ue ON ue.id={$latest} "
            . 'WHERE ' . implode(' AND ', $where) . $filterSql
            . " ORDER BY membership_active DESC, COALESCE(ue.expires_at,'9999-12-31 23:59:59') ASC, u.username ASC LIMIT 500";

        $statement = $this->database->prepare($sql);
        $statement->execute($params);
        $items = $statement->fetchAll();

        $counts = $this->database->query(
            "SELECT COUNT(*) total_users,"
            . "SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) active,"
            . "SUM(CASE WHEN {$active} AND ue.expires_at IS NOT NULL AND ue.expires_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) THEN 1 ELSE 0 END) expiring,"
            . "SUM(CASE WHEN {$active} AND ue.expires_at IS NULL THEN 1 ELSE 0 END) lifetime,"
            . "SUM(CASE WHEN {$scheduled} THEN 1 ELSE 0 END) scheduled,"
            . "SUM(CASE WHEN ue.id IS NOT NULL AND NOT ({$active}) AND NOT ({$scheduled}) THEN 1 ELSE 0 END) inactive,"
            . "SUM(CASE WHEN ue.id IS NULL THEN 1 ELSE 0 END) never "
            . "FROM users u LEFT JOIN user_entitlements ue ON ue.id={$latest} WHERE u.deleted_at IS NULL"
        )->fetch() ?: [];

        return ['items'=>array_values($items),'counts'=>[
            'total'=>(int)($counts['total_users']??0),
            'active'=>(int)($counts['active']??0),
            'expiring'=>(int)($counts['expiring']??0),
            'lifetime'=>(int)($counts['lifetime']??0),
            'scheduled'=>(int)($counts['scheduled']??0),
            'inactive'=>(int)($counts['inactive']??0),
            'never'=>(int)($counts['never']??0),
        ]];
    }


    /** @return array<string,mixed>|null */
    public function user(int $userId): ?array
    {
        $statement=$this->database->prepare(
            'SELECT id,username,email,status,created_at FROM users WHERE id=:id AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['id'=>$userId]);
        $row=$statement->fetch();
        return is_array($row)?$row:null;
    }

    /** @return list<array<string,mixed>> */
    public function history(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT ue.id,ue.entitlement,ue.plan_key,ue.source,ue.note,ue.starts_at,ue.expires_at,ue.revoked_at,'
            . 'ue.created_at,ue.updated_at,g.username AS granted_by_username '
            . 'FROM user_entitlements ue LEFT JOIN users g ON g.id=ue.granted_by '
            . 'WHERE ue.user_id=:user_id ORDER BY ue.id DESC LIMIT 100'
        );
        $statement->execute(['user_id'=>$userId]);
        return array_values($statement->fetchAll());
    }
}
