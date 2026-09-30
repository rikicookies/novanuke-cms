<?php

declare(strict_types=1);

namespace NovaNuke\Core\Http;

final class SafeReturnPath
{
    /** @param list<string> $allowed */
    public static function choose(mixed $candidate, array $allowed, string $fallback): string
    {
        return is_string($candidate) && in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    private function __construct()
    {
    }
}
