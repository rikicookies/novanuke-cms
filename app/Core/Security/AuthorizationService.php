<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

use PDO;

final class AuthorizationService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function allows(int $userId, string $permission): bool
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM user_roles ur '
            . 'INNER JOIN roles r ON r.id = ur.role_id '
            . 'LEFT JOIN role_permissions rp ON rp.role_id = r.id '
            . 'LEFT JOIN permissions p ON p.id = rp.permission_id '
            . 'WHERE ur.user_id = :user_id AND (r.slug = :super OR p.slug = :permission)'
        );
        $statement->execute([
            'user_id' => $userId,
            'super' => 'super-administrator',
            'permission' => $permission,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @return list<string> */
    public function permissions(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT DISTINCT p.slug FROM user_roles ur '
            . 'INNER JOIN role_permissions rp ON rp.role_id = ur.role_id '
            . 'INNER JOIN permissions p ON p.id = rp.permission_id '
            . 'WHERE ur.user_id = :user_id ORDER BY p.slug'
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

}
