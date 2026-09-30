<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use PDO;

final class AccountSecurityRepository
{
    public function __construct(private readonly PDO $database, private readonly LoginHistoryPresenter $presenter)
    {
    }

    /** @return list<array<string,mixed>> */
    public function recentLogins(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $this->database->prepare(
            "SELECT ip_address,user_agent,logged_in_at FROM user_login_history WHERE user_id=:id ORDER BY logged_in_at DESC,id DESC LIMIT {$limit}"
        );
        $statement->execute(['id' => $userId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['device'] = $this->presenter->device((string) $row['user_agent']);
            unset($row['user_agent']);
        }
        unset($row);

        return $rows;
    }
}
