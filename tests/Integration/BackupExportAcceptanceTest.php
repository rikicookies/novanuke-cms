<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Backup\BackupExportBundle;
use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use InvalidArgumentException;
use RuntimeException;

final class BackupExportAcceptanceTest extends MySqlIntegrationTestCase
{
    private string $root;
    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/novanuke-backup-export-' . bin2hex(random_bytes(6));
        $this->backupDirectory = $this->root . '/storage/private/backups';
        foreach (['modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki', 'storage/private/backups'] as $path) mkdir($this->root . '/' . $path, 0700, true);
        file_put_contents($this->root . '/modules/fixture.txt', 'fixture');
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPlaintextBundlePreservesVerifiedSetAndCanBeVerifiedOffline(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create();
        $destination = $this->root . '/plaintext-export.tar';
        $result = (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $destination);
        self::assertFileExists($destination);
        self::assertFalse($result['encrypted']);
        $verified = (new BackupExportBundle($this->backupDirectory))->verify($destination);
        self::assertSame($set['backup_set_id'], $verified['backup_set_id']);
        self::assertSame('manifest-and-artifacts-verified', $verified['verification']);
        self::assertFileExists($set['manifest']);
    }

    public function testEncryptedBundlePreservesCiphertextWithoutPassphrase(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create('operator-only-secret');
        $originalDatabaseHash = hash_file('sha256', $set['database']);
        $destination = $this->root . '/encrypted-export.tar';
        $result = (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $destination);
        self::assertTrue($result['encrypted']);
        self::assertSame('manifest-and-ciphertext-verified; passphrase-required-for-aead-verification', $result['verification']);
        $verified = (new BackupExportBundle($this->backupDirectory))->verify($destination);
        self::assertSame($set['backup_set_id'], $verified['backup_set_id']);
        self::assertSame($originalDatabaseHash, hash_file('sha256', $set['database']));
        self::assertStringNotContainsString('operator-only-secret', (string) file_get_contents($destination));
    }

    public function testLegacyManifestExportsWithoutInventingRecoveryInventory(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create();
        $manifest = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        unset($manifest['recovery_inventory']);
        file_put_contents($set['manifest'], json_encode($manifest, JSON_THROW_ON_ERROR));
        $destination = $this->root . '/legacy-export.tar';
        (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $destination);
        $verified = (new BackupExportBundle($this->backupDirectory))->verify($destination);
        self::assertArrayNotHasKey('recovery_inventory', $verified['manifest']);
        self::assertSame($set['backup_set_id'], $verified['backup_set_id']);
    }

    public function testManifestContainingPrivateSourcePathIsNotExported(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory))->create();
        $manifest = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $manifest['warnings'][] = $this->root;
        file_put_contents($set['manifest'], json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        (new BackupExportBundle($this->backupDirectory))->create($set['backup_set_id'], $this->root . '/private-path-export.tar');
    }

    public function testCorruptMissingAndInvalidExportInputsFailClosed(): void
    {
        $bundle = new BackupExportBundle($this->backupDirectory);
        try { $bundle->create('../outside', $this->root . '/invalid.tar'); self::fail('Invalid backup IDs must be rejected.'); }
        catch (InvalidArgumentException $error) { self::assertStringContainsString('invalid', strtolower($error->getMessage())); }
        file_put_contents($this->root . '/corrupt.tar', 'not a tar');
        try { $bundle->verify($this->root . '/corrupt.tar'); self::fail('Corrupt export bundles must be rejected.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('tar', strtolower($error->getMessage())); }
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($root);
    }
}
