<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

final class MigrationFileSet
{
    /**
     * @param list<string> $executed
     * @return array{total:int,executed:int,pending:list<string>,missing_files:list<string>}
     */
    public function compare(string $directory, array $executed): array
    {
        $files = is_dir($directory) ? (glob(rtrim($directory, '/') . '/*.php') ?: []) : [];
        $available = array_map(static fn (string $file): string => basename($file, '.php'), $files);
        sort($available, SORT_STRING);
        $executed = array_values(array_unique(array_map('strval', $executed)));
        sort($executed, SORT_STRING);

        return [
            'total' => count($available),
            'executed' => count(array_intersect($available, $executed)),
            'pending' => array_values(array_diff($available, $executed)),
            'missing_files' => array_values(array_diff($executed, $available)),
        ];
    }
}
