<?php

declare(strict_types=1);

namespace NovaNuke\Core\Menus;

final class MenuTreeBuilder
{
    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    public function build(array $items): array
    {
        $byParent = [];
        foreach ($items as $item) {
            $key = $item['parent_id'] === null ? 0 : (int) $item['parent_id'];
            $byParent[$key][] = $item;
        }
        foreach ($byParent as &$children) {
            usort($children, static fn (array $a, array $b): int => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
        }

        return $this->children(0, $byParent, []);
    }

    /** @param array<int, list<array<string, mixed>>> $byParent @param array<int, true> $ancestors */
    private function children(int $parent, array $byParent, array $ancestors): array
    {
        $result = [];
        foreach ($byParent[$parent] ?? [] as $item) {
            $id = (int) $item['id'];
            if (isset($ancestors[$id])) {
                continue;
            }
            $branch = $ancestors;
            $branch[$id] = true;
            $item['children'] = $this->children($id, $byParent, $branch);
            $result[] = $item;
        }
        return $result;
    }
}
