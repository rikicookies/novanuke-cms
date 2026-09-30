<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final class ResetToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }
}
