<?php

declare(strict_types=1);

namespace NovaNuke\Core\Admin;

final class DashboardPrioritySorter
{
    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    public function sort(array $items): array
    {
        usort($items, static function (array $left, array $right): int {
            $priority = (int) $right['priority'] <=> (int) $left['priority'];
            return $priority !== 0 ? $priority : strcmp((string) $left['label'], (string) $right['label']);
        });

        return $items;
    }
}
