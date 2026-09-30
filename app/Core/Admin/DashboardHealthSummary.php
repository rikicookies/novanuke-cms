<?php

declare(strict_types=1);

namespace NovaNuke\Core\Admin;

final class DashboardHealthSummary
{
    /** @param array<string,mixed> $system
     *  @return array{status:string,checks:list<array{label:string,value:string,status:string,url:string}>}
     */
    public function summarize(array $system): array
    {
        $migrations = is_array($system['migrations'] ?? null) ? $system['migrations'] : [];
        $pending = (int) ($migrations['pending_total'] ?? 0);
        $missing = (int) ($migrations['missing_total'] ?? 0);
        $recovery = (int) ($migrations['recovery_total'] ?? 0);
        $updates = (int) ($migrations['module_updates_total'] ?? 0);
        $unwritable = count(array_filter(
            is_array($system['writable'] ?? null) ? $system['writable'] : [],
            static fn (mixed $writable): bool => $writable !== true,
        ));
        $warnings = count(is_array($system['warnings'] ?? null) ? $system['warnings'] : []);

        $checks = [
            $this->check(
                'admin.dashboard.health.maintenance_mode',
                ($system['maintenance'] ?? false) ? 'admin.dashboard.value.enabled' : 'admin.dashboard.value.disabled',
                ($system['maintenance'] ?? false) ? 'warning' : 'ok',
                '/admin/settings',
            ),
            $this->check(
                'admin.dashboard.health.database_state',
                $recovery > 0 ? 'admin.dashboard.value.recovery_operations|' . $recovery : ($missing > 0 ? 'admin.dashboard.value.missing_migrations|' . $missing : ($pending + $updates > 0 ? 'admin.dashboard.value.pending_changes|' . ($pending + $updates) : 'admin.dashboard.value.up_to_date')),
                $recovery + $missing > 0 ? 'error' : ($pending + $updates > 0 ? 'warning' : 'ok'),
                '/admin/system',
            ),
            $this->check(
                'admin.dashboard.health.writable_storage',
                $unwritable > 0 ? 'admin.dashboard.value.paths_attention|' . $unwritable : 'admin.dashboard.value.ready',
                $unwritable > 0 ? 'error' : 'ok',
                '/admin/system',
            ),
            $this->check(
                'admin.dashboard.health.production_configuration',
                $warnings > 0 ? 'admin.dashboard.value.warnings|' . $warnings : 'admin.dashboard.value.baseline_passed',
                $warnings > 0 ? 'warning' : 'ok',
                '/admin/system',
            ),
        ];

        return [
            'status' => count(array_filter($checks, static fn (array $check): bool => $check['status'] !== 'ok')) > 0
                ? 'attention'
                : 'healthy',
            'checks' => $checks,
        ];
    }

    /** @return array{label:string,value:string,status:string,url:string} */
    private function check(string $label, string $value, string $status, string $url): array
    {
        return compact('label', 'value', 'status', 'url');
    }
}
