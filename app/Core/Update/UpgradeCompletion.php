<?php

declare(strict_types=1);

namespace NovaNuke\Core\Update;

final class UpgradeCompletion
{
    /** @param array<string,mixed> $migrationStatus
     *  @return list<array{name:string,passed:bool,required:bool,detail:string}>
     */
    public function check(
        string $sourceVersion,
        string $targetVersion,
        ?string $recordedVersion,
        array $migrationStatus,
        bool $releasePassed,
    ): array {
        $validSource = preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $sourceVersion) === 1;
        $validTarget = preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $targetVersion) === 1;
        $recordedKnown = is_string($recordedVersion) && $recordedVersion !== '';
        $recordedValid = ! $recordedKnown
            || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $recordedVersion) === 1;
        $recordedMatches = ! $recordedKnown || ($recordedValid && $recordedVersion === $sourceVersion);
        $pending = (int) ($migrationStatus['pending_total'] ?? -1);
        $missing = (int) ($migrationStatus['missing_total'] ?? -1);
        $moduleUpdates = (int) ($migrationStatus['module_updates_total'] ?? -1);
        $recovery = (int) ($migrationStatus['recovery_total'] ?? 0);

        return [
            $this->result('Version syntax', $validSource && $validTarget, true, $validSource && $validTarget ? "{$sourceVersion} -> {$targetVersion}" : 'Source or target version is invalid.'),
            $this->result('No downgrade', $validSource && $validTarget && version_compare($targetVersion, $sourceVersion, '>='), true, "Target {$targetVersion}."),
            $this->result('Recorded source version', $recordedKnown && $recordedMatches, $recordedKnown, $recordedKnown ? "Recorded {$recordedVersion}; declared {$sourceVersion}." : 'Legacy installation has no recorded current version; this completion will initialize it.'),
            $this->result('Core and module migrations', $pending === 0 && $missing === 0 && $recovery === 0, true, "Pending {$pending}; missing files {$missing}; recovery required {$recovery}."),
            $this->result('Module versions', $moduleUpdates === 0, true, "{$moduleUpdates} installed module update(s) remain."),
            $this->result('Distribution release check', $releasePassed, true, $releasePassed ? 'Distribution checks passed.' : 'Resolve release:check failures first.'),
        ];
    }

    /** @return array{name:string,passed:bool,required:bool,detail:string} */
    private function result(string $name, bool $passed, bool $required, string $detail): array
    {
        return compact('name', 'passed', 'required', 'detail');
    }
}
