<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

final class PasswordChangeAccessPolicy
{
    public function blocks(string $path, bool $mustChangePassword): bool
    {
        if (! $mustChangePassword) return false;
        return ! in_array($path, ['/account/profile', '/account/password', '/logout'], true);
    }
}
