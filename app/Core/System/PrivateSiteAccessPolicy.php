<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

final class PrivateSiteAccessPolicy
{
    public function blocks(string $path, bool $enabled, bool $authenticated): bool
    {
        if (! $enabled || $authenticated) return false;

        $exact = ['/login', '/register', '/forgot-password', '/reset-password', '/resend-verification', '/health', '/install'];
        if (in_array($path, $exact, true)) return false;
        foreach (['/reset-password/', '/verify-email/', '/install/'] as $prefix) {
            if (str_starts_with($path, $prefix)) return false;
        }

        return true;
    }
}
