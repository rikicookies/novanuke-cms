<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

use PDO;

final class AuthorizationAudit
{
    private const CORE_PERMISSIONS = [
        'admin.access', 'users.view', 'users.manage', 'users.assign_roles', 'roles.view', 'roles.manage',
        'settings.manage', 'logs.view', 'modules.manage', 'themes.manage', 'blocks.manage', 'menus.manage',
    ];

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array{label:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $activeSupers = (int) $this->database->query(
            "SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id "
            . "INNER JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super-administrator' "
            . "AND u.status = 'active' AND u.deleted_at IS NULL"
        )->fetchColumn();

        $placeholders = implode(',', array_fill(0, count(self::CORE_PERMISSIONS), '?'));
        $statement = $this->database->prepare("SELECT slug FROM permissions WHERE slug IN ({$placeholders})");
        $statement->execute(self::CORE_PERMISSIONS);
        $present = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff(self::CORE_PERMISSIONS, $present));

        $publicAdminPermissions = (int) $this->database->query(
            "SELECT COUNT(*) FROM role_permissions rp INNER JOIN roles r ON r.id = rp.role_id "
            . "INNER JOIN permissions p ON p.id = rp.permission_id "
            . "WHERE r.slug IN ('guest', 'member') AND (p.slug = 'admin.access' OR p.slug LIKE '%.manage' "
            . "OR p.slug LIKE '%.moderate' OR p.slug LIKE '%.publish' OR p.slug LIKE '%.edit' "
            . "OR p.slug LIKE 'users.%' OR p.slug LIKE 'roles.%' OR p.slug LIKE 'settings.%' "
            . "OR p.slug LIKE 'logs.%' OR p.slug LIKE 'modules.%' OR p.slug LIKE 'themes.%' OR p.slug LIKE 'blocks.%')"
        )->fetchColumn();

        $superPermissionStatement=$this->database->prepare(
            "SELECT p.slug FROM permissions p "
            . "INNER JOIN role_permissions rp ON rp.permission_id=p.id "
            . "INNER JOIN roles r ON r.id=rp.role_id "
            . "WHERE r.slug='super-administrator' AND p.slug IN (" . $placeholders . ")"
        );
        $superPermissionStatement->execute(self::CORE_PERMISSIONS);
        $superPresent=array_map('strval',$superPermissionStatement->fetchAll(PDO::FETCH_COLUMN));
        $superMissing=array_values(array_diff(self::CORE_PERMISSIONS,$superPresent));

        $rolesMissingAdminAccess = (int) $this->database->query(
            "SELECT COUNT(DISTINCT r.id) FROM roles r INNER JOIN role_permissions rp ON rp.role_id = r.id "
            . "INNER JOIN permissions p ON p.id = rp.permission_id "
            . "WHERE (p.slug LIKE '%.manage' OR p.slug LIKE '%.moderate' OR p.slug LIKE '%.publish' "
            . "OR p.slug LIKE '%.edit' OR p.slug LIKE 'users.%' OR p.slug LIKE 'roles.%' "
            . "OR p.slug LIKE 'settings.%' OR p.slug LIKE 'logs.%' OR p.slug LIKE 'modules.%' "
            . "OR p.slug LIKE 'themes.%' OR p.slug LIKE 'blocks.%') "
            . "AND NOT EXISTS (SELECT 1 FROM role_permissions arp INNER JOIN permissions ap ON ap.id = arp.permission_id "
            . "WHERE arp.role_id = r.id AND ap.slug = 'admin.access')"
        )->fetchColumn();

        return [
            [
                'label' => 'Active Super Administrator',
                'passed' => $activeSupers >= 1,
                'detail' => $activeSupers >= 1 ? "{$activeSupers} active account(s)." : 'No active Super Administrator remains.',
            ],
            [
                'label' => 'Core permission catalog',
                'passed' => $missing === [],
                'detail' => $missing === [] ? 'All required core permissions exist.' : 'Missing: ' . implode(', ', $missing),
            ],
            [
                'label' => 'Super Administrator core permissions',
                'passed' => $superMissing === [],
                'detail' => $superMissing === []
                    ? 'Super Administrator retains every required core permission.'
                    : 'Missing: ' . implode(', ', $superMissing),
            ],
            [
                'label' => 'Public roles',
                'passed' => $publicAdminPermissions === 0,
                'detail' => $publicAdminPermissions === 0
                    ? 'Guest and Member have no administrative permissions.'
                    : "{$publicAdminPermissions} dangerous assignment(s) found on Guest or Member.",
            ],
            [
                'label' => 'Administrative role access',
                'passed' => $rolesMissingAdminAccess === 0,
                'detail' => $rolesMissingAdminAccess === 0
                    ? 'Every role with administrative permissions can enter the administration panel.'
                    : "{$rolesMissingAdminAccess} role(s) need the admin.access permission.",
            ],
        ];
    }
}
