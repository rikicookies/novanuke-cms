<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleManifest;
use NovaNuke\Core\Modules\ModuleMigrator;

final class MigrationStatus
{
    public function __construct(
        private readonly Migrator $core,
        private readonly ModuleMigrator $modules,
        private readonly ModuleManager $moduleManager,
        private readonly string $coreDirectory,
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $core = $this->core->status($this->coreDirectory);
        $modules = [];
        foreach ($this->moduleManager->inventory() as $slug => $item) {
            $manifest = $item['manifest'];
            if (! $item['installed'] || ! $manifest instanceof ModuleManifest) {
                continue;
            }
            $status = $this->modules->status($manifest);
            $modules[$slug] = [
                'name' => $manifest->name,
                'enabled' => (bool) $item['enabled'],
                'installed_version' => (string) $item['installed_version'],
                'available_version' => $manifest->version,
                'update_available' => (bool) $item['update_available'],
                ...$status,
            ];
        }
        ksort($modules);

        $pending = count($core['pending']);
        $missing = count($core['missing_files']);
        $updates = 0;
        foreach ($modules as $module) {
            $pending += count($module['pending']);
            $missing += count($module['missing_files']);
            $updates += $module['update_available'] ? 1 : 0;
        }
        $recovery = $this->core->unresolvedOperations();

        return [
            'core' => $core,
            'modules' => $modules,
            'pending_total' => $pending,
            'missing_total' => $missing,
            'module_updates_total' => $updates,
            'recovery' => $recovery,
            'recovery_total' => count($recovery),
        ];
    }
}
