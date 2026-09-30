<?php

declare(strict_types=1);

namespace Modules\Notifications\src;

use PDO;
use PDOException;
use RuntimeException;

final class NotificationRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function latest(int $userId, int $limit = 50): array
    {
        $statement = $this->database->prepare('SELECT id,type,title,message,url,read_at,created_at FROM notifications WHERE user_id=:user ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':user', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=:user AND read_at IS NULL');
        $statement->execute(['user' => $userId]);
        return (int) $statement->fetchColumn();
    }

    public function insert(int $userId, string $type, string $title, string $message, ?string $url, ?string $key): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO notifications (user_id,type,title,message,url,deduplication_key,created_at) '
            . 'VALUES (:user,:type,:title,:message,:url,:deduplication_key,UTC_TIMESTAMP())'
        );
        try {
            $statement->execute([
                'user' => $userId, 'type' => $type, 'title' => $title, 'message' => $message,
                'url' => $url, 'deduplication_key' => $key,
            ]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) return;
            throw $error;
        }
    }

    /** @return array<int,int> */
    public function usersWithPermission(string $permission): array
    {
        $statement = $this->database->prepare(
            "SELECT DISTINCT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id "
            . "LEFT JOIN role_permissions rp ON rp.role_id=ur.role_id LEFT JOIN permissions p ON p.id=rp.permission_id "
            . "WHERE (r.slug='super-administrator' OR p.slug=:permission) AND u.status='active' AND u.deleted_at IS NULL"
        );
        $statement->execute(['permission' => $permission]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function markRead(int $id, int $userId): void
    {
        $statement = $this->database->prepare('UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=:id AND user_id=:user');
        $statement->execute(['id' => $id, 'user' => $userId]);
        if ($statement->rowCount() !== 1) {
            $exists = $this->database->prepare('SELECT COUNT(*) FROM notifications WHERE id=:id AND user_id=:user');
            $exists->execute(['id' => $id, 'user' => $userId]);
            if ((int) $exists->fetchColumn() !== 1) throw new RuntimeException('Notification not found.');
        }
    }

    public function markAllRead(int $userId): int
    {
        $statement = $this->database->prepare('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE user_id=:user AND read_at IS NULL');
        $statement->execute(['user' => $userId]);
        return $statement->rowCount();
    }

    public function prune(bool $dryRun): int
    {
        $where = 'read_at IS NOT NULL AND read_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)';
        return $dryRun
            ? (int) $this->database->query("SELECT COUNT(*) FROM notifications WHERE {$where}")->fetchColumn()
            : (int) $this->database->exec("DELETE FROM notifications WHERE {$where}");
    }
}
