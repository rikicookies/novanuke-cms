<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Backup\PortableRecoveryInventory;
use NovaNuke\Core\Backup\PortableRecoveryReadiness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortableRecoveryInventoryTest extends TestCase
{
    public function testValidVersionedInventoryIsAcceptedWithoutSecretsOrAbsolutePaths(): void
    {
        $inventory = $this->inventory();
        PortableRecoveryInventory::validate($inventory);
        $encoded = json_encode($inventory, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('password', strtolower($encoded));
        self::assertStringNotContainsString('APP_KEY', $encoded);
        self::assertStringNotContainsString('C:\\\\', $encoded);
        self::assertFalse($inventory['encryption']['manifest_signed']);
    }

    public function testUnsupportedSchemaAndUnsafePathsAreRejected(): void
    {
        $inventory = $this->inventory();
        $inventory['schema_version'] = 99;
        $this->expectException(RuntimeException::class);
        PortableRecoveryInventory::validate($inventory);
    }

    public function testUnsafeArtifactNameIsRejected(): void
    {
        $inventory = $this->inventory();
        $inventory['artifacts']['files']['name'] = '../files.tar';
        $this->expectException(RuntimeException::class);
        PortableRecoveryInventory::validate($inventory);
    }

    public function testWindowsArtifactPathAndIncompleteSchemaAreRejected(): void
    {
        $inventory = $this->inventory();
        $inventory['artifacts']['files']['name'] = 'C:\\private\\files.tar';
        try {
            PortableRecoveryInventory::validate($inventory);
            self::fail('A Windows absolute artifact path must be rejected.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        unset($inventory['filesystem']['document_root']);
        $this->expectException(RuntimeException::class);
        PortableRecoveryInventory::validate($inventory);
    }

    public function testReadinessNeverCallsLegacyManifestFullyPortable(): void
    {
        $result = (new PortableRecoveryReadiness())->evaluate(['format' => 1], true, true);
        self::assertFalse($result['portable']);
        self::assertSame('legacy', $result['inventory_state']);
        self::assertContains('Legacy manifest has no recovery inventory schema.', $result['issues']);
    }

    /** @return array<string,mixed> */
    private function inventory(): array
    {
        return [
            'schema_version' => 2,
            'backup_set_id' => 'set-20261010000000-0123456789abcdef01234567',
            'created_at' => '2026-10-10T00:00:00+00:00',
            'core' => ['version' => '0.4.0-beta.1', 'build' => 'unavailable', 'source' => 'runtime'],
            'runtime' => ['php_version' => '8.3.33', 'required_extensions' => [['name' => 'pdo_mysql', 'available' => true]], 'composer_lock_sha256' => str_repeat('a', 64)],
            'database' => ['driver' => 'mysql', 'server_version' => '8.0', 'character_set' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'snapshot' => 'consistent-inno-db', 'snapshot_consistent' => true],
            'modules' => [],
            'themes' => ['active' => 'novamodern', 'installed' => []],
            'filesystem' => ['included_roots' => ['modules'], 'excluded_roots' => ['.env', 'storage/cache'], 'document_root' => 'public', 'application_outside_document_root' => true, 'filesystem_snapshot' => 'non-atomic'],
            'artifacts' => ['database' => ['name' => 'db.sql', 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'encrypted' => false, 'state' => 'verified'], 'files' => ['name' => 'files.tar', 'bytes' => 1, 'sha256' => str_repeat('b', 64), 'encrypted' => false, 'state' => 'verified']],
            'encryption' => ['state' => 'plaintext', 'manifest_signed' => false, 'authenticity' => 'not-established'],
            'migrations' => ['state' => 'complete', 'core' => ['executed' => 1, 'pending' => 0, 'missing' => 0], 'digest' => str_repeat('c', 64)],
            'recovery_test' => ['performed' => false, 'scope' => 'not-recorded'],
            'manual_recovery_inputs' => ['compatible_core_release_and_vendor'],
            'warnings' => ['Filesystem files are collected without a transactional filesystem snapshot.'],
        ];
    }
}
