<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Core\Backup\BackupVerifier;
use NovaNuke\Core\Backup\PortableRecoveryInventory;
use NovaNuke\Core\Backup\PortableRecoveryReadiness;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;

final class PortableRecoveryManifestIntegrationTest extends MySqlIntegrationTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'novanuke-recovery-manifest-' . bin2hex(random_bytes(8));
        foreach (['modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki', 'storage/private/backups'] as $directory) {
            self::assertTrue(mkdir($this->root . DIRECTORY_SEPARATOR . $directory, 0700, true));
        }
        file_put_contents($this->root . '/public/uploads/fixture.txt', 'portable fixture');
        mkdir($this->root . '/modules/Fixture', 0700, true);
        file_put_contents($this->root . '/modules/Fixture/module.json', json_encode([
            'name' => 'Fixture Module', 'slug' => 'fixture', 'version' => '1.0.0', 'provider' => 'Modules\\Fixture\\src\\FixtureModule',
            'cms_min_version' => '0.4.0-beta.1', 'php_min_version' => '8.3.0', 'dependencies' => [], 'permissions' => [], 'events' => [],
        ], JSON_THROW_ON_ERROR));
        mkdir($this->root . '/themes/fixture-theme', 0700, true);
        file_put_contents($this->root . '/themes/fixture-theme/theme.json', json_encode([
            'name' => 'Fixture Theme', 'slug' => 'fixture-theme', 'version' => '1.0.0', 'cms_min_version' => '0.4.0-beta.1',
        ], JSON_THROW_ON_ERROR));
        $this->db()->exec("INSERT INTO modules (slug,name,installed_version,enabled,manifest,installed_at,updated_at) VALUES ('fixture','Fixture Module','1.0.0',1,'{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $this->db()->exec("INSERT INTO themes (slug,name,installed_version,manifest,settings,installed_at,updated_at) VALUES ('fixture-theme','Fixture Theme','1.0.0','{}','{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPlaintextSetAddsVersionedInventoryAndLegacyVerificationStillWorks(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->root . '/storage/private/backups'))->create();
        $manifest = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $inventory = $manifest['recovery_inventory'];

        self::assertSame(2, $inventory['schema_version']);
        self::assertSame($set['backup_set_id'], $inventory['backup_set_id']);
        self::assertSame($manifest['components']['database']['sha256'], $inventory['artifacts']['database']['sha256']);
        self::assertSame($manifest['components']['files']['bytes'], $inventory['artifacts']['files']['bytes']);
        self::assertContains('.env', $inventory['filesystem']['excluded_roots']);
        self::assertContains('storage/installed.lock', $inventory['filesystem']['excluded_roots']);
        self::assertSame('fixture', $inventory['modules'][0]['id']);
        self::assertSame('compatible', $inventory['modules'][0]['compatibility']);
        self::assertSame('fixture-theme', $inventory['themes']['installed'][0]['id']);
        self::assertFalse($inventory['encryption']['manifest_signed']);
        self::assertSame('not-established', $inventory['encryption']['authenticity']);

        $verified = (new BackupVerifier($this->root . '/storage/private/backups'))->verifyManifest($set['manifest']);
        self::assertSame(2, $verified['manifest']['recovery_inventory']['schema_version']);
        $rebuilt = (new PortableRecoveryInventory($this->db(), dirname(__DIR__, 2)))->build($set['backup_set_id'], $inventory['created_at'], ['snapshot' => 'unknown', 'snapshot_consistent' => false], $manifest['components']);
        self::assertNotEmpty($rebuilt['manual_recovery_inputs']);
        $readiness = (new PortableRecoveryReadiness())->evaluate($verified['manifest'], true, false);
        self::assertFalse($readiness['portable']);
        self::assertContains('Disposable recovery has not been performed.', $readiness['issues']);
    }

    public function testEncryptedSetCarriesArtifactIdentityWithoutPassphraseOrPrivatePath(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->root . '/storage/private/backups'))->create('portable-passphrase');
        $manifest = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $encoded = json_encode($manifest['recovery_inventory'], JSON_THROW_ON_ERROR);

        self::assertSame('authenticated-encrypted', $manifest['recovery_inventory']['encryption']['state']);
        self::assertSame($manifest['components']['database']['sha256'], $manifest['recovery_inventory']['artifacts']['database']['sha256']);
        self::assertStringNotContainsString('portable-passphrase', $encoded);
        self::assertStringNotContainsString($this->root, $encoded);
        $verified = (new BackupVerifier($this->root . '/storage/private/backups'))->verifyManifest($set['manifest'], false, 'portable-passphrase');
        self::assertSame(2, $verified['manifest']['recovery_inventory']['schema_version']);
    }

    public function testMalformedInventoryIsRejectedWithoutChangingDatabase(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->root . '/storage/private/backups'))->create();
        $raw = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $raw['recovery_inventory']['filesystem']['included_roots'][] = '../outside';
        file_put_contents($set['manifest'], json_encode($raw, JSON_THROW_ON_ERROR));
        $before = (int) $this->db()->query('SELECT COUNT(*) FROM migrations')->fetchColumn();

        $this->expectException(\RuntimeException::class);
        try {
            (new BackupVerifier($this->root . '/storage/private/backups'))->verifyManifest($set['manifest']);
        } finally {
            self::assertSame($before, (int) $this->db()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        }
    }

    public function testInventoryArtifactIdentityMustMatchManifestComponents(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->root . '/storage/private/backups'))->create();
        $raw = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        $raw['recovery_inventory']['artifacts']['files']['sha256'] = str_repeat('f', 64);
        file_put_contents($set['manifest'], json_encode($raw, JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        (new BackupVerifier($this->root . '/storage/private/backups'))->verifyManifest($set['manifest']);
    }

    public function testLegacyManifestWithoutInventoryRemainsVerifiable(): void
    {
        $set = (new BackupSetCoordinator($this->db(), $this->root, $this->root . '/storage/private/backups'))->create();
        $manifest = json_decode((string) file_get_contents($set['manifest']), true, 32, JSON_THROW_ON_ERROR);
        unset($manifest['recovery_inventory']);
        file_put_contents($set['manifest'], json_encode($manifest, JSON_THROW_ON_ERROR));

        $verified = (new BackupVerifier($this->root . '/storage/private/backups'))->verifyManifest($set['manifest']);
        $readiness = (new PortableRecoveryReadiness())->evaluate($verified['manifest'], true, false);
        self::assertArrayNotHasKey('recovery_inventory', $verified['manifest']);
        self::assertSame('legacy', $readiness['inventory_state']);
        self::assertFalse($readiness['portable']);
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }
}
