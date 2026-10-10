<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use NovaNuke\Core\Version;

final class PortableRecoveryReadiness
{
    /** @param array<string,mixed> $manifest
     *  @return array{verified:bool,recovery_tested:bool,portable:bool,issues:list<string>,manual_inputs:list<string>,inventory_state:string}
     */
    public function evaluate(array $manifest, bool $verified = false, bool $recoveryTested = false): array
    {
        $inventory = $manifest['recovery_inventory'] ?? null;
        $issues = [];
        $manual = [];
        if (! is_array($inventory)) {
            return [
                'verified' => $verified,
                'recovery_tested' => $recoveryTested,
                'portable' => false,
                'issues' => ['Legacy manifest has no recovery inventory schema.'],
                'manual_inputs' => ['compatible release, vendor, .env and hosting configuration'],
                'inventory_state' => 'legacy',
            ];
        }
        $recoveryTested = $recoveryTested || (($inventory['recovery_test']['performed'] ?? false) === true);
        if (($inventory['core']['version'] ?? null) !== Version::CURRENT) $issues[] = 'Core version differs from the running CMS.';
        foreach (($inventory['runtime']['required_extensions'] ?? []) as $extension) {
            if (($extension['available'] ?? true) !== true) $issues[] = 'A required PHP extension was unavailable when the set was created.';
        }
        foreach (($inventory['modules'] ?? []) as $module) {
            if (in_array($module['compatibility'] ?? '', ['incompatible', 'missing-source', 'unavailable'], true)) $issues[] = 'Module inventory contains an incompatible or unavailable entry.';
        }
        foreach (($inventory['themes']['installed'] ?? []) as $theme) {
            if (in_array($theme['compatibility'] ?? '', ['incompatible', 'unavailable'], true)) $issues[] = 'Theme inventory contains an incompatible or unavailable entry.';
        }
        if (($inventory['migrations']['state'] ?? 'unknown') !== 'complete') $issues[] = 'Migration state is not complete.';
        if (($inventory['database']['snapshot_consistent'] ?? false) !== true) $issues[] = 'Database snapshot consistency was not fully verified.';
        if (($inventory['filesystem']['filesystem_snapshot'] ?? '') !== 'atomic') $issues[] = 'Filesystem snapshot was not atomic.';
        foreach ((array) ($inventory['manual_recovery_inputs'] ?? []) as $input) if (is_string($input)) $manual[] = $input;
        if (! $verified) $issues[] = 'Backup artifacts have not been verified in this operation.';
        if (! $recoveryTested) $issues[] = 'Disposable recovery has not been performed.';
        return [
            'verified' => $verified,
            'recovery_tested' => $recoveryTested,
            'portable' => $verified && $recoveryTested && $issues === [],
            'issues' => array_values(array_unique($issues)),
            'manual_inputs' => $manual,
            'inventory_state' => 'v2',
        ];
    }
}
