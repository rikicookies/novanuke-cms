<?php

declare(strict_types=1);

namespace NovaNukeTests\Unit;

use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModulePackageInstaller;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class ModulePackageUpgradeTest extends TestCase
{
    private string $root;
    private string $modules;
    private string $fixture;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/novanuke-package-upgrade-' . bin2hex(random_bytes(6));
        $this->modules = $this->root . '/modules';
        $this->fixture = $this->modules . '/UpgradeFixture';
        mkdir($this->fixture . '/src', 0770, true);
        mkdir($this->root . '/storage/private', 0770, true);
        file_put_contents($this->fixture . '/module.json', $this->manifest('1.0.0'));
        file_put_contents($this->fixture . '/src/UpgradeFixtureModule.php', '<?php namespace Modules\\UpgradeFixture\\src;');
        file_put_contents($this->fixture . '/old.txt', 'old source');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testNewerPackageReplacesSourceAndInvokesDatabaseUpdateCallback(): void
    {
        $archive = $this->archive('2.0.0', 'new source');
        $called = false;
        $installer = $this->installer();

        $installer->upgrade($archive, $this->installed(), function ($manifest) use (&$called): void {
            $called = true;
            self::assertSame('2.0.0', $manifest->version);
        });

        self::assertTrue($called);
        self::assertSame('new source', file_get_contents($this->fixture . '/new.txt'));
        self::assertFileDoesNotExist($this->fixture . '/old.txt');
        self::assertSame('2.0.0', json_decode((string) file_get_contents($this->fixture . '/module.json'), true, 32, JSON_THROW_ON_ERROR)['version']);
        self::assertSame([], glob($this->root . '/storage/private/module-updates/*/*') ?: []);
    }

    public function testDatabaseFailureKeepsNewSourceAndPrivateRecoveryBackup(): void
    {
        $archive = $this->archive('2.0.0', 'new source');
        $installer = $this->installer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database update did not complete');
        try {
            $installer->upgrade($archive, $this->installed(), static function (): void {
                throw new RuntimeException('simulated migration failure');
            });
        } finally {
            self::assertFileExists($this->fixture . '/new.txt');
            self::assertFileDoesNotExist($this->fixture . '/old.txt');
            $backups = glob($this->root . '/storage/private/module-updates/upgrade-fixture/*/previous/old.txt') ?: [];
            self::assertCount(1, $backups);
            self::assertSame('old source', file_get_contents($backups[0]));
            $states = glob($this->root . '/storage/private/module-updates/upgrade-fixture/*/operation.json') ?: [];
            self::assertCount(1, $states);
            self::assertSame('database-update-failed', json_decode((string) file_get_contents($states[0]), true, 32, JSON_THROW_ON_ERROR)['state']);
        }
    }

    public function testDowngradeIsRejectedBeforeSourceChanges(): void
    {
        $archive = $this->archive('0.9.0', 'bad downgrade');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('newer');
        try {
            $this->installer()->upgrade($archive, $this->installed(), static function (): void {});
        } finally {
            self::assertSame('old source', file_get_contents($this->fixture . '/old.txt'));
            self::assertFileDoesNotExist($this->fixture . '/new.txt');
        }
    }

    public function testConcurrentUpgradeAttemptIsRejectedByModuleLock(): void
    {
        $archive = $this->archive('2.0.0', 'new source');
        $installer = $this->installer();
        $rejected = false;

        $installer->upgrade($archive, $this->installed(), function () use (&$rejected, $installer, $archive): void {
            try {
                $installer->upgrade($archive, $this->installed(), static function (): void {});
            } catch (RuntimeException $error) {
                $rejected = str_contains($error->getMessage(), 'already in progress');
            }
        });

        self::assertTrue($rejected);
    }

    public function testSourcePublicationFailureRestoresPreviousSource(): void
    {
        $archive = $this->archive('2.0.0', 'new source');
        $installer = new ModulePackageInstaller(
            $this->modules,
            new ModuleCompatibilityChecker('0.4.0-beta.1'),
            static function (string $from, string $to): bool {
                if (str_contains($from, '.install-')) return false;
                return rename($from, $to);
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('previous source restored');
        try {
            $installer->upgrade($archive, $this->installed(), static function (): void {});
        } finally {
            self::assertSame('old source', file_get_contents($this->fixture . '/old.txt'));
            self::assertFileDoesNotExist($this->fixture . '/new.txt');
            self::assertSame([], glob($this->root . '/storage/private/module-updates/*/*') ?: []);
        }
    }

    private function installer(): ModulePackageInstaller
    {
        return new ModulePackageInstaller($this->modules, new ModuleCompatibilityChecker('0.4.0-beta.1'));
    }

    /** @return array<string,array<string,mixed>> */
    private function installed(): array
    {
        return ['upgrade-fixture' => ['installed_version' => '1.0.0']];
    }

    private function archive(string $version, string $content): string
    {
        $source = $this->root . '/package';
        mkdir($source . '/UpgradeFixture/src', 0770, true);
        file_put_contents($source . '/UpgradeFixture/module.json', $this->manifest($version));
        file_put_contents($source . '/UpgradeFixture/src/UpgradeFixtureModule.php', '<?php namespace Modules\\UpgradeFixture\\src;');
        file_put_contents($source . '/UpgradeFixture/new.txt', $content);
        $archive = $this->root . '/package-' . $version . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) continue;
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $zip->addFile($file->getPathname(), $relative);
        }
        $zip->close();
        return $archive;
    }

    private function manifest(string $version): string
    {
        return json_encode([
            'name' => 'Upgrade Fixture',
            'slug' => 'upgrade-fixture',
            'version' => $version,
            'provider' => 'Modules\\UpgradeFixture\\src\\UpgradeFixtureModule',
            'cms_min_version' => '0.4.0-beta.1',
            'php_min_version' => '8.3.0',
            'permissions' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
