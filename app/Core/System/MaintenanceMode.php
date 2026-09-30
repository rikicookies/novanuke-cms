<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Settings\SettingsRepository;

final class MaintenanceMode
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuthManager $auth,
        private readonly bool $installed,
        private readonly MaintenanceAccessPolicy $policy = new MaintenanceAccessPolicy(),
    ) {
    }

    public function blocks(Request $request): bool
    {
        if (! $this->installed) return false;
        $enabled = $this->settings->boolean('system.maintenance', false);
        if (! $enabled) return false;

        $path = $request->path();
        $user = $this->auth->user();
        return $this->policy->blocks(
            $path,
            $this->installed,
            $enabled,
            $user !== null && $this->auth->isSuperAdministrator((int) $user['id']),
        );
    }
}
