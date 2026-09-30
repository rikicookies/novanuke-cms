<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use InvalidArgumentException;

final class BackupSetId
{
    public static function generate(): string
    {
        return 'set-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(12));
    }

    public static function normalize(?string $value): string
    {
        if ($value === null || $value === '') return self::generate();
        if (preg_match('/^set-[0-9]{14}-[a-f0-9]{24}$/', $value) !== 1) {
            throw new InvalidArgumentException('Backup set ID is invalid.');
        }
        return $value;
    }
}
