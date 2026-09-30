<?php

declare(strict_types=1);

namespace NovaNuke\Core\Admin;

final class DashboardContentMerger
{
    /** @param list<list<array<string,mixed>>> $groups
     *  @return list<array<string,mixed>>
     */
    public function merge(array $groups, int $limit = 8): array
    {
        $items = array_merge(...$groups);
        usort($items, static function (array $left, array $right): int {
            $date = strcmp((string) $right['created_at'], (string) $left['created_at']);
            return $date !== 0 ? $date : ((int) $right['id'] <=> (int) $left['id']);
        });

        return array_slice($items, 0, max(0, $limit));
    }
}
