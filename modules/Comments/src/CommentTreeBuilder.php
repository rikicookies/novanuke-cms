<?php

declare(strict_types=1);

namespace Modules\Comments\src;

final class CommentTreeBuilder
{
    public function build(array $comments): array
    {
        $children = [];
        foreach ($comments as $comment) $children[(int) ($comment['parent_id'] ?? 0)][] = $comment;
        return $this->branch(0, $children, 0);
    }

    private function branch(int $parent, array $children, int $depth): array
    {
        if ($depth > 5) return [];
        $result = [];
        foreach ($children[$parent] ?? [] as $comment) {
            $comment['children'] = $this->branch((int) $comment['id'], $children, $depth + 1);
            $result[] = $comment;
        }
        return $result;
    }
}
