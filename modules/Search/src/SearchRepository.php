<?php

declare(strict_types=1);

namespace Modules\Search\src;

use PDO;

final class SearchRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function record(string $normalizedTerm): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO search_queries (term,search_count,last_searched_at) VALUES (:term,1,UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE search_count=search_count+1,last_searched_at=UTC_TIMESTAMP()'
        );
        $statement->execute(['term' => $normalizedTerm]);
    }

    public function popular(int $limit = 25): array
    {
        $statement = $this->database->prepare('SELECT term,search_count,last_searched_at FROM search_queries ORDER BY search_count DESC,last_searched_at DESC LIMIT :limit');
        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function prune(bool $dryRun): int
    {
        $where = 'last_searched_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)';
        return $dryRun
            ? (int) $this->database->query("SELECT COUNT(*) FROM search_queries WHERE {$where}")->fetchColumn()
            : (int) $this->database->exec("DELETE FROM search_queries WHERE {$where}");
    }
}
