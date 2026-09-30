<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

final class MaintenanceAccessPolicy
{
    public function blocks(string $path, bool $installed, bool $enabled, bool $isSuperAdministrator): bool
    {
        if (! $installed || ! $enabled || $isSuperAdministrator) return false;

        $exact = ['/login', '/logout', '/forgot-password', '/reset-password', '/resend-verification', '/admin', '/health'];
        if (in_array($path, $exact, true)) return false;
        foreach (['/reset-password/', '/verify-email/', '/admin/'] as $prefix) {
            if (str_starts_with($path, $prefix)) return false;
        }
        return true;
    }
}
