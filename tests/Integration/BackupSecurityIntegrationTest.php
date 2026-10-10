<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Core\Backup\BackupSetStatus;
use NovaNuke\Core\Backup\BackupVerifier;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;

final class BackupSecurityIntegrationTest extends MySqlIntegrationTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'novanuke-backup-root-' . bin2hex(random_bytes(8));
        foreach (['modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki', 'storage/private/backups'] as $directory) {
            self::assertTrue(mkdir($this->root . DIRECTORY_SEPARATOR . $directory, 0700, true));
        }
        file_put_contents($this->root . '/public/uploads/fixture.txt', 'disposable backup fixture');
        @chmod($this->root . '/public/uploads/fixture.txt', 0600);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testEncryptedBackupSetUsesDisposableMysqlAndVerifiesBeforeRecovery(): void
    {
        $coordinator = new BackupSetCoordinator($this->db(), $this->root, $this->root . '/storage/private/backups');
        $set = $coordinator->create('integration-passphrase');
        $manifest = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);

        self::assertTrue($manifest['components']['database']['encrypted']);
        self::assertTrue($manifest['components']['files']['encrypted']);
        self::assertStringEndsWith('.nnb', $set['database']);
        self::assertStringEndsWith('.nnb', $set['files']);
        self::assertSame([], glob(dirname($set['manifest']) . '/*.sql') ?: []);
        self::assertSame([], glob(dirname($set['manifest']) . '/*.tar') ?: []);

        $verified = (new BackupVerifier($this->root . '/storage/private/backups'))->verifyManifest($set['manifest'], false, 'integration-passphrase');
        self::assertSame($set['backup_set_id'], $verified['manifest']['backup_set_id']);
        self::assertSame($manifest['components']['database']['plaintext_sha256'], $verified['database']['sha256']);
        self::assertSame('valid', (new BackupSetStatus($this->root . '/storage/private/backups'))->inspect('integration-passphrase')[0]['status']);

        $this->expectException(\RuntimeException::class);
        (new BackupVerifier($this->root . '/storage/private/backups'))->verifyManifest($set['manifest'], false, 'wrong-passphrase');
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($root);
    }
}
