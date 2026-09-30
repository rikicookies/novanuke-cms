<?php

declare(strict_types=1);

namespace NovaNuke\Core\Search;

final readonly class SearchQuery
{
    public function __construct(
        public string $term,
        public int $limit,
        public ?int $userId,
    ) {
        if ($term === '' || $limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Invalid provider search query.');
        }
    }
}
