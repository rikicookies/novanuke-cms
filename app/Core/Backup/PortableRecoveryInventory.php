<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Themes\ThemeDetector;
use NovaNuke\Core\Version;
use PDO;
use Throwable;

final class PortableRecoveryInventory
{
    public const SCHEMA_VERSION = 2;

    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = ['dom', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql'];

    public function __construct(private readonly PDO $database, private readonly string $rootPath)
    {
    }

    /** @param array<string,mixed> $databaseMetadata
     *  @param array<string,array<string,mixed>> $components
     *  @return array<string,mixed>
     */
    public function build(string $backupSetId, string $completedAt, array $databaseMetadata, array $components): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'backup_set_id' => $backupSetId,
            'created_at' => $completedAt,
            'core' => ['version' => Version::CURRENT, 'build' => 'unavailable', 'source' => 'runtime'],
            'runtime' => ['php_version' => PHP_VERSION, 'required_extensions' => $this->extensions(), 'composer_lock_sha256' => $this->composerLock()],
            'database' => [
                'driver' => $this->databaseValue(PDO::ATTR_DRIVER_NAME),
                'server_version' => $this->databaseValue(PDO::ATTR_SERVER_VERSION),
                'character_set' => $this->databaseSetting('@@character_set_database'),
                'collation' => $this->databaseSetting('@@collation_database'),
                'snapshot' => $this->safeText($databaseMetadata['snapshot'] ?? 'unknown', 64),
                'snapshot_consistent' => ($databaseMetadata['snapshot_consistent'] ?? false) === true,
            ],
            'modules' => $this->modules(),
            'themes' => ['active' => $this->activeTheme(), 'installed' => $this->themes()],
            'filesystem' => [
                'included_roots' => ['modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki'],
                'excluded_roots' => ['.env', 'vendor', '.git', 'app', 'config', 'bootstrap', 'routes', 'resources', 'bin', 'composer.json', 'composer.lock', 'storage/installed.lock', 'storage/cache', 'storage/logs', 'storage/sessions', 'storage/private/backups', 'public/assets'],
                'document_root' => 'public',
                'application_outside_document_root' => true,
                'filesystem_snapshot' => 'non-atomic',
            ],
            'artifacts' => ['database' => $this->artifact($components['database'] ?? null), 'files' => $this->artifact($components['files'] ?? null)],
            'encryption' => ['state' => $this->encryptionState($components), 'manifest_signed' => false, 'authenticity' => 'not-established'],
            'migrations' => $this->migrations(),
            'recovery_test' => ['performed' => false, 'scope' => 'not-recorded'],
            'manual_recovery_inputs' => ['compatible_core_release_and_vendor', 'new_database_name_username_password', 'new_app_environment_and_runtime_secrets', 'document_root_and_rewrite_configuration', 'site_url_dns_tls_and_mail_configuration', 'cron_or_scheduled_task_configuration', 'operator_custody_for_backup_passphrase_if_encrypted'],
            'warnings' => [
                ...(($databaseMetadata['snapshot_consistent'] ?? false) === true ? [] : ['Database snapshot consistency is not fully verified.']),
                'Filesystem files are collected without a transactional filesystem snapshot.',
                'Manifest integrity is verified separately from external authenticity.',
                'Recovery has not been proven unless a disposable recovery check is recorded.',
            ],
        ];
    }

    /** @param array<string,mixed> $inventory */
    public static function validate(array $inventory): void
    {
        self::assertKeys($inventory, ['schema_version', 'backup_set_id', 'created_at', 'core', 'runtime', 'database', 'modules', 'themes', 'filesystem', 'artifacts', 'encryption', 'migrations', 'recovery_test', 'manual_recovery_inputs', 'warnings']);
        if (($inventory['schema_version'] ?? null) !== self::SCHEMA_VERSION) throw new \RuntimeException('Recovery inventory schema is unsupported.');
        self::assertSafeId($inventory['backup_set_id'] ?? null);
        self::assertUtc($inventory['created_at'] ?? null);
        $core = self::object($inventory['core'], ['version', 'build', 'source'], 'core');
        self::assertText($core['version'], 64);
        self::assertText($core['build'], 64);
        self::assertText($core['source'], 64);
        $runtime = self::object($inventory['runtime'], ['php_version', 'required_extensions', 'composer_lock_sha256'], 'runtime');
        self::assertText($runtime['php_version'], 64);
        self::assertHashOrUnknown($runtime['composer_lock_sha256']);
        if (! is_array($runtime['required_extensions']) || ! array_is_list($runtime['required_extensions'])) throw new \RuntimeException('Recovery inventory extension metadata is invalid.');
        foreach ($runtime['required_extensions'] as $extension) {
            if (! is_array($extension) || ! is_string($extension['name'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]*$/', $extension['name']) || ! is_bool($extension['available'] ?? null)) throw new \RuntimeException('Recovery inventory extension metadata is invalid.');
        }
        if (! is_array($inventory['modules']) || ! array_is_list($inventory['modules'])) throw new \RuntimeException('Recovery inventory module metadata is invalid.');
        foreach ($inventory['modules'] as $module) {
            if (! is_array($module)) throw new \RuntimeException('Recovery inventory module metadata is invalid.');
            self::assertKeys($module, ['id', 'version', 'installed', 'enabled', 'installed_version', 'audience', 'cms_min_version', 'php_min_version', 'compatibility']);
            if (! is_string($module['id'] ?? null) || ! preg_match('/^(unknown|[a-z][a-z0-9-]{0,99})$/', $module['id'])
                || ! is_bool($module['installed'] ?? null) || ! is_bool($module['enabled'] ?? null)
                || ! is_string($module['compatibility'] ?? null) || ! in_array($module['compatibility'], ['compatible', 'incompatible', 'missing-source', 'unavailable'], true)) {
                throw new \RuntimeException('Recovery inventory module metadata is invalid.');
            }
            self::assertVersionOrUnknown($module['version']);
            self::assertVersionOrUnknown($module['installed_version']);
            self::assertAudience($module['audience']);
            self::assertVersionOrUnknown($module['cms_min_version']);
            self::assertVersionOrUnknown($module['php_min_version']);
        }
        $themes = self::object($inventory['themes'], ['active', 'installed'], 'theme');
        if (! is_string($themes['active']) || ! preg_match('/^(unknown|[a-z][a-z0-9-]{0,99})$/', $themes['active'])) throw new \RuntimeException('Recovery inventory theme metadata is invalid.');
        if (! is_array($themes['installed']) || ! array_is_list($themes['installed'])) throw new \RuntimeException('Recovery inventory theme metadata is invalid.');
        foreach ($themes['installed'] as $theme) {
            if (! is_array($theme)) throw new \RuntimeException('Recovery inventory theme metadata is invalid.');
            self::assertKeys($theme, ['id', 'version', 'installed', 'installed_version', 'cms_min_version', 'compatibility']);
            if (! is_string($theme['id'] ?? null) || ! preg_match('/^(unknown|[a-z][a-z0-9-]{0,99})$/', $theme['id'])
                || ! is_bool($theme['installed'] ?? null)
                || ! is_string($theme['compatibility'] ?? null) || ! in_array($theme['compatibility'], ['compatible', 'incompatible', 'unavailable'], true)) {
                throw new \RuntimeException('Recovery inventory theme metadata is invalid.');
            }
            self::assertVersionOrUnknown($theme['version']);
            self::assertVersionOrUnknown($theme['installed_version']);
            self::assertVersionOrUnknown($theme['cms_min_version']);
        }
        $filesystem = self::object($inventory['filesystem'], ['included_roots', 'excluded_roots', 'document_root', 'application_outside_document_root', 'filesystem_snapshot'], 'filesystem');
        foreach (['included_roots', 'excluded_roots'] as $field) {
            if (! is_array($filesystem[$field]) || ! array_is_list($filesystem[$field])) throw new \RuntimeException('Recovery inventory filesystem metadata is invalid.');
            foreach ($filesystem[$field] as $path) self::assertRelativePath($path);
        }
        self::assertRelativePath($filesystem['document_root']);
        if (! is_bool($filesystem['application_outside_document_root']) || ! in_array($filesystem['filesystem_snapshot'], ['atomic', 'non-atomic'], true)) throw new \RuntimeException('Recovery inventory filesystem metadata is invalid.');
        $artifacts = self::object($inventory['artifacts'], ['database', 'files'], 'artifact');
        foreach (['database', 'files'] as $type) {
            $artifact = $artifacts[$type] ?? null;
            if (! is_array($artifact)) throw new \RuntimeException("Recovery inventory {$type} artifact metadata is invalid.");
            self::assertKeys($artifact, ['name', 'bytes', 'sha256', 'encrypted', 'state']);
            if (! is_string($artifact['name']) || $artifact['name'] === 'unknown' && $artifact['state'] !== 'unavailable' || $artifact['name'] !== basename(str_replace('\\', '/', $artifact['name'])) || str_contains($artifact['name'], '/') || preg_match('/[\x00-\x1F\x7F]/', $artifact['name']) === 1 || ! is_int($artifact['bytes']) || $artifact['bytes'] < 0 || ! is_string($artifact['sha256']) || preg_match('/^[a-f0-9]{64}$/', $artifact['sha256']) !== 1 || ! is_bool($artifact['encrypted']) || ! in_array($artifact['state'], ['verified', 'unavailable'], true)) throw new \RuntimeException("Recovery inventory {$type} artifact metadata is invalid.");
        }
        $encryption = self::object($inventory['encryption'], ['state', 'manifest_signed', 'authenticity'], 'encryption');
        if (! in_array($encryption['state'], ['plaintext', 'partially-encrypted', 'authenticated-encrypted'], true) || $encryption['manifest_signed'] !== false || $encryption['authenticity'] !== 'not-established') throw new \RuntimeException('Recovery inventory security metadata is invalid.');
        $migrations = self::object($inventory['migrations'], ['state', 'core', 'digest'], 'migration', ['unresolved_operations']);
        if (! in_array($migrations['state'], ['complete', 'pending', 'attention', 'unknown'], true) || ! is_array($migrations['core']) || ! is_string($migrations['digest']) || ! in_array($migrations['digest'], ['unknown'], true) && preg_match('/^[a-f0-9]{64}$/', $migrations['digest']) !== 1) throw new \RuntimeException('Recovery inventory migration metadata is invalid.');
        self::assertKeys($migrations['core'], ['executed', 'pending', 'missing']);
        foreach ($migrations['core'] as $count) if (! (is_int($count) && $count >= 0) && $count !== 'unknown') throw new \RuntimeException('Recovery inventory migration metadata is invalid.');
        if (array_key_exists('unresolved_operations', $migrations) && (! is_int($migrations['unresolved_operations']) || $migrations['unresolved_operations'] < 0)) throw new \RuntimeException('Recovery inventory migration metadata is invalid.');
        $recovery = self::object($inventory['recovery_test'], ['performed', 'scope'], 'recovery-test');
        if (! is_bool($recovery['performed']) || ! is_string($recovery['scope']) || strlen($recovery['scope']) > 64) throw new \RuntimeException('Recovery inventory recovery-test metadata is invalid.');
        foreach (['manual_recovery_inputs', 'warnings'] as $field) self::assertTextList($inventory[$field], $field);
    }

    private function composerLock(): string
    {
        $path = $this->rootPath . '/composer.lock';
        $hash = is_file($path) && ! is_link($path) ? hash_file('sha256', $path) : false;
        return is_string($hash) ? $hash : 'unknown';
    }

    /** @return list<array{name:string,available:bool}> */
    private function extensions(): array
    {
        $names = ['dom', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql'];
        return array_map(static fn (string $name): array => ['name' => $name, 'available' => extension_loaded($name)], $names);
    }

    private function databaseValue(int $attribute): string
    {
        try { return $this->safeText((string) $this->database->getAttribute($attribute), 128); } catch (Throwable) { return 'unknown'; }
    }

    private function databaseSetting(string $expression): string
    {
        try { return $this->safeText((string) $this->database->query("SELECT {$expression}")->fetchColumn(), 128); } catch (Throwable) { return 'unknown'; }
    }

    /** @return list<array<string,mixed>> */
    private function modules(): array
    {
        try {
            $detected = (new ModuleDetector($this->rootPath . '/modules'))->detect();
            $rows = $this->tableExists('modules') ? $this->database->query('SELECT slug, installed_version, enabled, audience FROM modules ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC) : [];
            $records = [];
            foreach ($rows as $row) $records[(string) $row['slug']] = $row;
            $result = [];
            foreach ($detected as $slug => $manifest) {
                $record = $records[$slug] ?? null;
                $result[] = ['id' => $this->safeSlug($slug), 'version' => $this->safeText($manifest->version, 64), 'installed' => is_array($record), 'enabled' => is_array($record) && (bool) $record['enabled'], 'installed_version' => is_array($record) ? $this->safeText($record['installed_version'] ?? 'unknown', 64) : 'unknown', 'audience' => is_array($record) ? $this->safeAudience($record['audience'] ?? 'unknown') : 'unknown', 'cms_min_version' => $this->safeText($manifest->cmsMinVersion, 64), 'php_min_version' => $this->safeText($manifest->phpMinVersion, 64), 'compatibility' => version_compare(Version::CURRENT, $manifest->cmsMinVersion, '>=') && version_compare(PHP_VERSION, $manifest->phpMinVersion, '>=') ? 'compatible' : 'incompatible'];
            }
            foreach ($records as $slug => $record) {
                if (isset($detected[$slug])) continue;
                $result[] = ['id' => $this->safeSlug($slug), 'version' => 'unknown', 'installed' => true, 'enabled' => (bool) $record['enabled'], 'installed_version' => $this->safeText($record['installed_version'] ?? 'unknown', 64), 'audience' => $this->safeAudience($record['audience'] ?? 'unknown'), 'cms_min_version' => 'unknown', 'php_min_version' => 'unknown', 'compatibility' => 'missing-source'];
            }
            usort($result, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
            return $result;
        } catch (Throwable) {
            return [['id' => 'unknown', 'version' => 'unknown', 'installed' => false, 'enabled' => false, 'installed_version' => 'unknown', 'audience' => 'unknown', 'cms_min_version' => 'unknown', 'php_min_version' => 'unknown', 'compatibility' => 'unavailable']];
        }
    }

    /** @return list<array<string,mixed>> */
    private function themes(): array
    {
        try {
            $detected = (new ThemeDetector($this->rootPath . '/themes'))->detect();
            $rows = $this->tableExists('themes') ? $this->database->query('SELECT slug, installed_version FROM themes ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC) : [];
            $records = [];
            foreach ($rows as $row) $records[(string) $row['slug']] = $row;
            $result = [];
            foreach ($detected as $slug => $manifest) $result[] = ['id' => $this->safeSlug($slug), 'version' => $this->safeText($manifest->version, 64), 'installed' => isset($records[$slug]), 'installed_version' => $this->safeText($records[$slug]['installed_version'] ?? 'unknown', 64), 'cms_min_version' => $this->safeText($manifest->cmsMinVersion, 64), 'compatibility' => version_compare(Version::CURRENT, $manifest->cmsMinVersion, '>=') ? 'compatible' : 'incompatible'];
            usort($result, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
            return $result;
        } catch (Throwable) {
            return [['id' => 'unknown', 'version' => 'unknown', 'installed' => false, 'installed_version' => 'unknown', 'cms_min_version' => 'unknown', 'compatibility' => 'unavailable']];
        }
    }

    private function activeTheme(): string
    {
        try {
            if (! $this->tableExists('settings')) return 'unknown';
            $statement = $this->database->prepare('SELECT settings.value FROM settings WHERE settings.key = :key LIMIT 1');
            $statement->execute(['key' => 'theme.active']);
            $value = $statement->fetchColumn();
            return $value === false ? 'unknown' : $this->safeSlug((string) $value);
        } catch (Throwable) { return 'unknown'; }
    }

    /** @return array<string,mixed> */
    private function migrations(): array
    {
        try {
            if (! $this->tableExists('migrations')) return ['state' => 'unknown', 'core' => ['executed' => 'unknown', 'pending' => 'unknown', 'missing' => 'unknown'], 'digest' => 'unknown'];
            $executed = array_map('strval', $this->database->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN));
            $available = array_map(static fn (string $path): string => basename($path, '.php'), glob($this->rootPath . '/database/migrations/*.php') ?: []);
            sort($available, SORT_STRING);
            $missing = array_values(array_diff($executed, $available));
            $pending = array_values(array_diff($available, $executed));
            $operations = $this->tableExists('migration_operations') ? $this->database->query("SELECT COUNT(*) FROM migration_operations WHERE state IN ('running','dirty')")->fetchColumn() : 0;
            $payload = ['executed' => $executed, 'available' => $available, 'missing' => $missing, 'pending' => $pending];
            return ['state' => ((int) $operations > 0 || $missing !== []) ? 'attention' : ($pending === [] ? 'complete' : 'pending'), 'core' => ['executed' => count($executed), 'pending' => count($pending), 'missing' => count($missing)], 'unresolved_operations' => (int) $operations, 'digest' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
        } catch (Throwable) { return ['state' => 'unknown', 'core' => ['executed' => 'unknown', 'pending' => 'unknown', 'missing' => 'unknown'], 'digest' => 'unknown']; }
    }

    /** @return array<string,mixed> */
    private function artifact(mixed $artifact): array
    {
        if (! is_array($artifact)) return ['name' => 'unknown', 'bytes' => 0, 'sha256' => str_repeat('0', 64), 'encrypted' => false, 'state' => 'unavailable'];
        return ['name' => basename((string) ($artifact['name'] ?? 'unknown')), 'bytes' => max(0, (int) ($artifact['bytes'] ?? 0)), 'sha256' => preg_match('/^[a-f0-9]{64}$/', (string) ($artifact['sha256'] ?? '')) === 1 ? $artifact['sha256'] : str_repeat('0', 64), 'encrypted' => ($artifact['encrypted'] ?? false) === true, 'state' => 'verified'];
    }

    private function encryptionState(array $components): string
    {
        $database = ($components['database']['encrypted'] ?? false) === true;
        $files = ($components['files']['encrypted'] ?? false) === true;
        return $database && $files ? 'authenticated-encrypted' : ($database || $files ? 'partially-encrypted' : 'plaintext');
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $this->database->quote($table))->fetchColumn() === 1;
    }

    private function safeSlug(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9-]{0,99}$/', $value) === 1 ? $value : 'unknown';
    }

    private function safeAudience(mixed $value): string
    {
        return is_string($value) && in_array($value, ['public', 'guest', 'member', 'vip'], true) ? $value : 'unknown';
    }

    private function safeText(mixed $value, int $max): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) return 'unknown';
        return $value;
    }

    private static function assertSafeId(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^set-[0-9]{14}-[a-f0-9]{24}$/', $value) !== 1) throw new \RuntimeException('Recovery inventory backup-set ID is invalid.');
    }

    private static function assertUtc(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?\+00:00$/', $value) !== 1 || strtotime($value) === false) throw new \RuntimeException('Recovery inventory timestamp is invalid.');
    }

    private static function assertRelativePath(mixed $path): void
    {
        if (! is_string($path) || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || preg_match('/^[A-Za-z]:/', $path) === 1) throw new \RuntimeException('Recovery inventory path is unsafe.');
    }

    private static function assertText(mixed $value, int $max): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) throw new \RuntimeException('Recovery inventory text metadata is invalid.');
    }

    /** @param array<string,mixed> $value @param list<string> $required @param list<string> $optional */
    private static function object(mixed $value, array $required, string $label, array $optional = []): array
    {
        if (! is_array($value)) throw new \RuntimeException("Recovery inventory {$label} metadata is invalid.");
        self::assertKeys($value, $required, $optional);
        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $required @param list<string> $optional */
    private static function assertKeys(array $value, array $required, array $optional = []): void
    {
        foreach ($required as $key) if (! array_key_exists($key, $value)) throw new \RuntimeException('Recovery inventory schema is incomplete.');
        foreach (array_keys($value) as $key) if (! in_array((string) $key, [...$required, ...$optional], true)) throw new \RuntimeException('Recovery inventory schema contains an unsupported field.');
    }

    private static function assertHashOrUnknown(mixed $value): void
    {
        if ($value !== 'unknown' && (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1)) throw new \RuntimeException('Recovery inventory hash metadata is invalid.');
    }

    private static function assertVersionOrUnknown(mixed $value): void
    {
        if ($value !== 'unknown' && (! is_string($value) || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $value) !== 1)) throw new \RuntimeException('Recovery inventory version metadata is invalid.');
    }

    private static function assertAudience(mixed $value): void
    {
        if (! is_string($value) || ! in_array($value, ['public', 'guest', 'member', 'vip', 'unknown'], true)) throw new \RuntimeException('Recovery inventory audience metadata is invalid.');
    }

    private static function assertTextList(mixed $value, string $label): void
    {
        if (! is_array($value) || ! array_is_list($value)) throw new \RuntimeException("Recovery inventory {$label} metadata is invalid.");
        foreach ($value as $item) self::assertText($item, 256);
    }
}
