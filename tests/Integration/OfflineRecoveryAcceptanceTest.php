<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Backup\BackupExportBundle;
use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Core\Backup\OfflineRecovery;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use RuntimeException;

final class OfflineRecoveryAcceptanceTest extends MySqlIntegrationTestCase
{
    private string $root;
    private string $backupDirectory;
    /** @var list<array{pdo:PDO,name:string}> */
    private array $extraDatabases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/novanuke-offline-recovery-' . bin2hex(random_bytes(6));
        $this->backupDirectory = $this->root . '/storage/private/backups';
        foreach (['modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki', 'storage/private/backups'] as $path) mkdir($this->root . '/' . $path, 0700, true);
        file_put_contents($this->root . '/modules/recovery-fixture.txt', 'offline-recovery-fixture');
    }

    protected function tearDown(): void
    {
        foreach ($this->extraDatabases as $database) {
            try { $database['pdo']->exec('DROP DATABASE IF EXISTS `' . $database['name'] . '`'); } catch (\Throwable) {}
        }
        if (isset($this->root) && is_dir($this->root)) $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPreviewAndRestorePlaintextBundleIntoDisposableEmptyTargets(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create();
        $bundlePath = $this->root . '/plain.tar';
        (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $bundlePath);
        $recovery = new OfflineRecovery(new BackupExportBundle($this->backupDirectory), $this->root);
        $preview = $recovery->preview($bundlePath);
        self::assertSame(OfflineRecovery::READY, $preview['status']);
        self::assertTrue($preview['recovery_inventory_v2']);
        self::assertSame($set['backup_set_id'], $preview['backup_set_id']);
        self::assertStringNotContainsString($this->root, json_encode($preview, JSON_THROW_ON_ERROR));

        $target = sys_get_temp_dir() . '/novanuke-recovered-files-' . bin2hex(random_bytes(6));
        $database = $this->newEmptyDatabase();
        $manifestHash = hash_file('sha256', $set['manifest']);
        $result = $recovery->restore($bundlePath, $database['pdo'], $target, null, true);
        self::assertSame('RESTORED_AND_VERIFIED', $result['status']);
        self::assertGreaterThan(0, $result['database']['tables']);
        self::assertArrayHasKey('migrations', $result['database']['row_counts']);
        self::assertSame('offline-recovery-fixture', file_get_contents($target . '/modules/recovery-fixture.txt'));
        self::assertSame($manifestHash, hash_file('sha256', $set['manifest']));
        self::assertSame(0, (int) $database['pdo']->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchColumn());
        $this->removeTree($target);
    }

    public function testEncryptedPreviewRequiresPassphraseAndRestoresWithIt(): void
    {
        $secret = 'offline-operator-secret';
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create($secret);
        $bundlePath = $this->root . '/encrypted.tar';
        (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $bundlePath);
        $recovery = new OfflineRecovery(new BackupExportBundle($this->backupDirectory), $this->root);
        self::assertSame(OfflineRecovery::NEEDS_PASSPHRASE, $recovery->preview($bundlePath)['status']);
        self::assertSame(OfflineRecovery::READY, $recovery->preview($bundlePath, $secret)['status']);
        $database = $this->newEmptyDatabase();
        $target = sys_get_temp_dir() . '/novanuke-recovered-encrypted-' . bin2hex(random_bytes(6));
        self::assertSame('RESTORED_AND_VERIFIED', $recovery->restore($bundlePath, $database['pdo'], $target, $secret, true)['status']);
        self::assertSame('offline-recovery-fixture', file_get_contents($target . '/modules/recovery-fixture.txt'));
        $this->removeTree($target);
    }

    public function testLegacyPreviewIsExplicitlyInsufficientAndUnsafeTargetsAreRejected(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create();
        $manifest = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        unset($manifest['recovery_inventory']);
        file_put_contents($set['manifest'], json_encode($manifest, JSON_THROW_ON_ERROR));
        $bundlePath = $this->root . '/legacy.tar';
        (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $bundlePath);
        $recovery = new OfflineRecovery(new BackupExportBundle($this->backupDirectory), $this->root);
        self::assertSame(OfflineRecovery::INSUFFICIENT_METADATA, $recovery->preview($bundlePath)['status']);
        $target = sys_get_temp_dir() . '/novanuke-offline-nonempty-' . bin2hex(random_bytes(6));
        mkdir($target, 0700, true);
        file_put_contents($target . '/existing.txt', 'must remain');
        $database = $this->newEmptyDatabase();
        try { $recovery->restore($bundlePath, $database['pdo'], $target, null, true); self::fail('Nonempty filesystem targets must be rejected.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('empty', strtolower($error->getMessage())); }
        self::assertSame('must remain', file_get_contents($target . '/existing.txt'));
        try { $recovery->restore($bundlePath, $database['pdo'], $this->root . '/active-target', null, true); self::fail('Active application paths must be rejected.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('active', strtolower($error->getMessage())); }
        $this->removeTree($target);
    }

    public function testConfirmationAndNonemptyDatabaseAreRequired(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create();
        $bundlePath = $this->root . '/confirm.tar';
        (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $bundlePath);
        $recovery = new OfflineRecovery(new BackupExportBundle($this->backupDirectory), $this->root);
        $target = sys_get_temp_dir() . '/novanuke-confirm-' . bin2hex(random_bytes(6));
        try { $recovery->restore($bundlePath, $this->db(), $target, null, false); self::fail('Confirmation must be required.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('confirm', strtolower($error->getMessage())); }
        $database = $this->newEmptyDatabase();
        $database['pdo']->exec('CREATE TABLE existing_guard (id INT NOT NULL)');
        try { $recovery->restore($bundlePath, $database['pdo'], $target, null, true); self::fail('Nonempty databases must be rejected.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('empty', strtolower($error->getMessage())); }
    }

    public function testTraversalDestinationIsRejectedBeforeAnyFilesystemMutation(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create();
        $bundlePath = $this->root . '/traversal.tar';
        (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $bundlePath);
        $database = $this->newEmptyDatabase();
        $escape = $this->root . '/../novanuke-offline-recovery-escape-' . bin2hex(random_bytes(6));
        try {
            (new OfflineRecovery(new BackupExportBundle($this->backupDirectory), $this->root))->restore($bundlePath, $database['pdo'], $escape, null, true);
            self::fail('Traversal filesystem destinations must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unsafe', strtolower($error->getMessage()));
        }
        self::assertFalse(file_exists($escape));
    }

    /** @return array{pdo:PDO,name:string} */
    private function newEmptyDatabase(): array
    {
        $host = (string) env('NOVANUKE_TEST_DB_HOST', '127.0.0.1');
        $port = (int) env('NOVANUKE_TEST_DB_PORT', '3306');
        $username = (string) env('NOVANUKE_TEST_DB_USERNAME', 'root');
        $password = (string) env('NOVANUKE_TEST_DB_PASSWORD', '');
        $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $name = 'novanuke_test_' . bin2hex(random_bytes(8));
        $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->extraDatabases[] = ['pdo' => $server, 'name' => $name];
        return ['pdo' => $pdo, 'name' => $name];
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root) || is_link($root)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($root);
    }
}
