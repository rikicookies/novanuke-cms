<?php

declare(strict_types=1);

namespace NovaNuke\Core\Update;

use NovaNuke\Core\Backup\BackupVerifier;

final class UpgradeReadiness
{
    /** @param list<string> $supportedSources */
    public function __construct(
        private readonly string $rootPath,
        private readonly string $targetVersion,
        private readonly array $supportedSources,
        private readonly int $backupMaxAge = 86400,
    ) {
    }

    /** @param array<string,mixed> $migrationStatus
     *  @return list<array{name:string,passed:bool,required:bool,detail:string}>
     */
    public function check(
        string $sourceVersion,
        array $migrationStatus,
        ?int $now = null,
        ?string $recordedVersion = null,
    ): array
    {
        $now ??= time();
        $supported = in_array($sourceVersion, $this->supportedSources, true);
        $forward = version_compare($this->targetVersion, $sourceVersion, '>=');
        $installationLock = $this->installationLock();
        $backupResults = [];
        foreach ((new BackupVerifier($this->rootPath . '/storage/private/backups'))->verifyLatest() as $result) {
            $backupResults[$result['type']] = $result;
        }
        $databaseBackup = $this->verifiedRecentBackup($backupResults['database'], '/^novanuke-db-[A-Za-z0-9.-]+\.sql$/', $now);
        $fileBackup = $this->verifiedRecentBackup($backupResults['files'], '/^novanuke-files-[A-Za-z0-9.-]+\.tar$/', $now);
        $backupPair = $backupResults['pair'];
        $missing = (int) ($migrationStatus['missing_total'] ?? -1);
        $pending = (int) ($migrationStatus['pending_total'] ?? -1);
        $moduleUpdates = (int) ($migrationStatus['module_updates_total'] ?? -1);
        $recovery = (int) ($migrationStatus['recovery_total'] ?? 0);
        $recordedKnown = is_string($recordedVersion) && $recordedVersion !== '';
        $recordedValid = ! $recordedKnown
            || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $recordedVersion) === 1;
        $recordedMatches = ! $recordedKnown || ($recordedValid && $recordedVersion === $sourceVersion);

        return [
            $this->result('Supported source release', $supported, true, $supported ? $sourceVersion : 'Direct upgrade is not documented from ' . $sourceVersion . '.'),
            $this->result('No downgrade', $forward, true, $forward ? "Target {$this->targetVersion}." : "Installed source {$sourceVersion} is newer than this package."),
            $this->result('Recorded source version', $recordedKnown && $recordedMatches, $recordedKnown, $recordedKnown ? "Recorded {$recordedVersion}; declared {$sourceVersion}." : 'Legacy installation has no recorded current version.'),
            $this->result('Installation lock', $installationLock !== null, true, $installationLock ?? 'storage/installed.lock is missing or invalid.'),
            $this->result('Environment file', is_file($this->rootPath . '/.env') && ! is_link($this->rootPath . '/.env'), true, '.env must be preserved.'),
            $this->result('Verified database backup', $databaseBackup['passed'], true, $databaseBackup['detail']),
            $this->result('Verified file backup', $fileBackup['passed'], true, $fileBackup['detail']),
            $this->result('Matched backup pair', $backupPair['passed'], true, $backupPair['detail']),
            $this->result('Migration files present', $missing === 0, true, $missing < 0 ? 'Migration status is unavailable.' : "{$missing} executed migration file(s) missing."),
            $this->result('No interrupted migration', $recovery === 0, true, "{$recovery} running/dirty migration operation(s) require recovery."),
            $this->result('Pending migrations', $pending === 0, false, $pending < 0 ? 'Migration status is unavailable.' : "{$pending} migration(s) pending."),
            $this->result('Module updates', $moduleUpdates === 0, false, $moduleUpdates < 0 ? 'Migration status is unavailable.' : "{$moduleUpdates} installed module update(s) available."),
        ];
    }

    /** @param array{type:string,passed:bool,file:string,detail:string} $result
     *  @return array{passed:bool,detail:string}
     */
    private function verifiedRecentBackup(array $result, string $filenamePattern, int $now): array
    {
        if (! $result['passed']) return ['passed' => false, 'detail' => $result['detail']];
        if (preg_match($filenamePattern, $result['file']) !== 1 || basename($result['file']) !== $result['file']) {
            return ['passed' => false, 'detail' => 'Verified backup filename is invalid.'];
        }
        $path = (string) ($result['metadata']['path'] ?? '');
        if ($path === '') $path = $this->rootPath . '/storage/private/backups/' . $result['file'];
        if (! is_file($path) || is_link($path)) {
            return ['passed' => false, 'detail' => 'Verified backup is no longer a regular file.'];
        }
        $modified = filemtime($path);
        if ($modified === false || $modified > $now || $modified < $now - $this->backupMaxAge) {
            return ['passed' => false, 'detail' => $result['file'] . ' is not a backup from the last 24 hours.'];
        }
        return ['passed' => true, 'detail' => $result['file'] . ': ' . $result['detail']];
    }

    private function installationLock(): ?string
    {
        $path = $this->rootPath . '/storage/installed.lock';
        if (! is_file($path) || is_link($path)) return null;
        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') return null;
        $record = json_decode($contents, true);
        if (! is_array($record)
            || ! is_string($record['version'] ?? null)
            || ! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $record['version'])
            || ! is_string($record['installed_at'] ?? null)
            || strtotime($record['installed_at']) === false) return null;
        return 'Installed originally with ' . $record['version'] . '.';
    }

    /** @return array{name:string,passed:bool,required:bool,detail:string} */
    private function result(string $name, bool $passed, bool $required, string $detail): array
    {
        return compact('name', 'passed', 'required', 'detail');
    }
}
