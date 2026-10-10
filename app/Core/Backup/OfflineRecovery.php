<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use PDO;
use RuntimeException;
use Throwable;

final class OfflineRecovery
{
    public const READY = 'READY_FOR_CONTROLLED_RESTORE';
    public const NEEDS_PASSPHRASE = 'NEEDS_PASSPHRASE';
    public const INCOMPATIBLE = 'INCOMPATIBLE';
    public const CORRUPT = 'CORRUPT';
    public const INSUFFICIENT_METADATA = 'INSUFFICIENT_METADATA';

    public function __construct(
        private readonly BackupExportBundle $bundle,
        private readonly string $activeRoot,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(string $bundlePath, ?string $passphrase = null): array
    {
        try {
            return $this->bundle->withVerifiedArtifacts($bundlePath, $passphrase, function (array $context) use ($passphrase): array {
                $manifest = $context['manifest'];
                $inventory = $manifest['recovery_inventory'] ?? null;
                $encrypted = $context['encrypted'] === true;
                $status = $encrypted && $passphrase === null ? self::NEEDS_PASSPHRASE : ($inventory === null ? self::INSUFFICIENT_METADATA : self::READY);
                return [
                    'status' => $status,
                    'backup_set_id' => $context['backup_set_id'],
                    'created_at' => (string) ($manifest['completed_at'] ?? $context['descriptor']['created_at'] ?? 'unknown'),
                    'core_version' => (string) ($manifest['cms_version'] ?? ($inventory['core']['version'] ?? 'unknown')),
                    'php_version' => (string) ($manifest['php_version'] ?? ($inventory['runtime']['php_version'] ?? 'unknown')),
                    'encryption' => $encrypted ? 'preserved-ciphertext' : 'plaintext',
                    'verification' => $passphrase === null && $encrypted ? 'ciphertext-and-envelope-verified; authenticated-decryption-required' : 'manifest-and-artifacts-authenticated',
                    'database' => $this->artifactSummary($manifest, 'database'),
                    'files' => $this->artifactSummary($manifest, 'files'),
                    'recovery_inventory_v2' => is_array($inventory),
                    'modules' => is_array($inventory) ? $this->safeList($inventory['modules'] ?? []) : [],
                    'themes' => is_array($inventory) ? ['active' => (string) ($inventory['themes']['active'] ?? 'unknown'), 'installed' => $this->safeList($inventory['themes']['installed'] ?? [])] : ['active' => 'unknown', 'installed' => []],
                    'included' => is_array($inventory) ? $this->safeStrings($inventory['filesystem']['included_roots'] ?? []) : $this->safeStrings($manifest['included'] ?? []),
                    'excluded' => is_array($inventory) ? $this->safeStrings($inventory['filesystem']['excluded_roots'] ?? []) : $this->safeStrings($manifest['excluded'] ?? []),
                    'manual_recovery_inputs' => is_array($inventory) ? $this->safeStrings($inventory['manual_recovery_inputs'] ?? []) : ['compatible_core_release_and_vendor', 'destination_database_credentials', 'runtime_secrets', 'document_root_and_hosting_configuration'],
                    'recovery_test' => is_array($inventory) ? ($inventory['recovery_test'] ?? ['performed' => false, 'scope' => 'unknown']) : ['performed' => false, 'scope' => 'unknown'],
                    'authenticity' => 'not-established-by-this-bundle',
                    'warnings' => [
                        'Preview does not execute SQL, PHP, modules or themes.',
                        'Database import and executable module/theme content require controlled operator review.',
                        ...($encrypted && $passphrase === null ? ['The external passphrase is required for authenticated decryption and restore.'] : []),
                        ...($inventory === null ? ['Legacy manifest: compatibility and recovery history are unknown.'] : []),
                    ],
                ];
            });
        } catch (Throwable $error) {
            $message = $error->getMessage();
            $status = str_contains($message, 'passphrase') ? self::NEEDS_PASSPHRASE : (str_contains($message, 'incompatible') ? self::INCOMPATIBLE : self::CORRUPT);
            return ['status' => $status, 'error' => $this->safeError($message), 'authenticity' => 'not-established-by-this-bundle'];
        }
    }

    /** @return array<string,mixed> */
    public function restore(string $bundlePath, PDO $database, string $filesDirectory, ?string $passphrase, bool $confirmed): array
    {
        if (! $confirmed) throw new RuntimeException('Explicit --confirm-empty-target is required.');
        $this->assertDisposableDatabase($database);
        $this->assertFilesTarget($filesDirectory);

        return $this->bundle->withVerifiedArtifacts($bundlePath, $passphrase, function (array $context) use ($database, $filesDirectory, $passphrase): array {
            if ($context['encrypted'] === true && $passphrase === null) throw new RuntimeException('An encryption passphrase is required for offline restore.');
            $temporary = $context['temporary_directory'];
            $databasePath = $context['database_path'];
            $filesPath = $context['files_path'];
            if ($context['encrypted'] === true) {
                $crypt = new BackupEncryption();
                $databasePath = $crypt->decryptToTemp($context['database_path'], $temporary, (string) $passphrase)['path'];
                $filesPath = $crypt->decryptToTemp($context['files_path'], $temporary, (string) $passphrase)['path'];
            }

            $databaseResult = (new DatabaseRestoreVerifier($database))->verify($databasePath);
            $fileResult = (new FileBackupRestorer(new BackupVerifier($temporary)))->restore($filesPath, $filesDirectory);
            return [
                'status' => 'RESTORED_AND_VERIFIED',
                'backup_set_id' => $context['backup_set_id'],
                'database' => $databaseResult,
                'files' => $fileResult,
                'source_manifest_unchanged' => true,
                'limitations' => ['Database and filesystem snapshots may have different capture times.', 'Restored modules and themes are never executed by this command.', 'Hosting, DNS, TLS, SMTP, cron and runtime secrets remain manual configuration.'],
            ];
        });
    }

    /** @return array<string,mixed> */
    private function artifactSummary(array $manifest, string $type): array
    {
        $component = $manifest['components'][$type] ?? [];
        return [
            'name' => basename((string) ($component['name'] ?? 'unknown')),
            'bytes' => (int) ($component['bytes'] ?? 0),
            'sha256' => (string) ($component['sha256'] ?? 'unknown'),
            'encrypted' => ($component['encrypted'] ?? false) === true,
        ];
    }

    private function assertDisposableDatabase(PDO $database): void
    {
        $name = (string) $database->query('SELECT DATABASE()')->fetchColumn();
        if (preg_match('/^novanuke_test_[a-f0-9]{16}$/', $name) !== 1) throw new RuntimeException('Offline restore requires a disposable novanuke_test_* database.');
        foreach (['DB_DATABASE', 'NOVANUKE_DB_DATABASE', 'DATABASE_NAME'] as $key) if (($configured = (string) getenv($key)) !== '' && hash_equals($configured, $name)) throw new RuntimeException('Offline restore refuses the configured application database.');
        $existing = (int) $database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
        if ($existing !== 0) throw new RuntimeException('Offline restore database must be empty before mutation.');
    }

    private function assertFilesTarget(string $destination): void
    {
        $normalized = str_replace('\\', '/', $destination);
        if (! $this->isAbsolute($destination) || is_link($destination) || preg_match('#(^|/)\.\.(?:/|$)#', $normalized) === 1 || preg_match('/[\x00-\x1F\x7F]/', $destination) === 1) throw new RuntimeException('Offline restore filesystem destination is unsafe.');
        $this->assertNoSymlinkAncestors($destination);
        $active = realpath($this->activeRoot);
        $candidate = realpath($destination) ?: $this->nearestExistingPath($destination);
        if ($active !== false && $candidate !== false && ($candidate === $active || str_starts_with($candidate, rtrim($active, '/\\') . DIRECTORY_SEPARATOR))) throw new RuntimeException('Offline restore cannot target the active application tree.');
        if (is_dir($destination) && array_values(array_diff(scandir($destination) ?: [], ['.', '..'])) !== []) throw new RuntimeException('Offline restore filesystem destination must be empty.');
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'));
    }

    private function assertNoSymlinkAncestors(string $path): void
    {
        $cursor = $path;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) throw new RuntimeException('Offline restore filesystem destination is unsafe.');
            $cursor = dirname($cursor);
        }
    }

    private function nearestExistingPath(string $path): string|false
    {
        $cursor = $path;
        while (! is_dir($cursor) && $cursor !== dirname($cursor)) $cursor = dirname($cursor);
        return is_dir($cursor) ? realpath($cursor) : false;
    }

    /** @return list<string> */
    private function safeStrings(mixed $values): array
    {
        if (! is_array($values)) return [];
        return array_values(array_filter(array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $values), static fn (string $value): bool => $value !== '' && ! str_contains($value, '\\') && ! str_starts_with($value, '/') && ! str_contains($value, '..')));
    }

    /** @return list<array<string,mixed>> */
    private function safeList(mixed $values): array
    {
        return is_array($values) && array_is_list($values) ? array_values(array_filter($values, static fn (mixed $value): bool => is_array($value))) : [];
    }

    private function safeError(string $message): string
    {
        if (str_contains($message, 'passphrase')) return 'An encryption passphrase is required for authenticated recovery.';
        return 'Recovery preview failed: the bundle is invalid, unavailable or incompatible.';
    }
}
