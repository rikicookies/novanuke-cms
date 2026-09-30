<?php

declare(strict_types=1);

namespace NovaNuke\Core\Blocks;

final class BlockVisibility
{
    /** @param list<string> $patterns */
    public function matches(string $mode, array $patterns, string $path): bool
    {
        if ($mode === 'all') {
            return true;
        }
        if ($patterns === []) {
            return $mode === 'except';
        }
        $matched = false;
        foreach ($patterns as $pattern) {
            $normalized = '/' . trim($pattern, '/');
            if ($normalized === '/') {
                $normalized = '/';
            }
            if ($path === $normalized || (str_ends_with($normalized, '/*')
                && str_starts_with($path . '/', rtrim($normalized, '*')))) {
                $matched = true;
                break;
            }
        }

        return $mode === 'only' ? $matched : ! $matched;
    }
}
