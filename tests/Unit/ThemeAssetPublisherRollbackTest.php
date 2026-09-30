<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Themes\ThemeAssetPublisher;
use NovaNuke\Core\Themes\ThemeManifest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ThemeAssetPublisherRollbackTest extends TestCase
{
    private string $root;
    private string $themePath;
    private string $publicPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/novanuke-theme-assets-' . bin2hex(random_bytes(6));
        $this->themePath = $this->root . '/theme';
        $this->publicPath = $this->root . '/public/themes';
        mkdir($this->themePath . '/assets', 0770, true);
        mkdir($this->publicPath . '/rollback-theme', 0770, true);
        file_put_contents($this->themePath . '/assets/app.css', 'new-css');
        file_put_contents($this->themePath . '/assets/app.js', 'new-js');
        file_put_contents($this->publicPath . '/rollback-theme/app.css', 'old-css');
        file_put_contents($this->publicPath . '/rollback-theme/app.js', 'old-js');
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->root)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testStagingVerificationFailureLeavesLiveAssetsUnchangedAndCleansStaging(): void
    {
        $publisher = new ThemeAssetPublisher(
            $this->publicPath,
            static function (string $source, string $target): bool {
                if (! copy($source, $target)) return false;
                if (str_ends_with($target, '/app.js')) file_put_contents($target, 'corrupt');
                return true;
            },
        );

        try {
            $publisher->publish($this->manifest());
            self::fail('Expected staged integrity verification to fail.');
        } catch (RuntimeException $error) {
            self::assertSame('A staged theme asset failed integrity verification.', $error->getMessage());
        }

        self::assertSame('old-css', file_get_contents($this->publicPath . '/rollback-theme/app.css'));
        self::assertSame('old-js', file_get_contents($this->publicPath . '/rollback-theme/app.js'));
        self::assertSame([], glob($this->publicPath . '/.rollback-theme.staging-*') ?: []);
        self::assertSame([], glob($this->publicPath . '/.rollback-theme.backup-*') ?: []);
    }

    public function testSwapFailureRestoresThePreviousLiveAssetDirectory(): void
    {
        $publisher = new ThemeAssetPublisher(
            $this->publicPath,
            null,
            static function (string $from, string $to): bool {
                if (str_contains($from, '.staging-')) return false;
                return rename($from, $to);
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not atomically publish the staged theme assets.');
        try {
            $publisher->publish($this->manifest());
        } finally {
            self::assertSame('old-css', file_get_contents($this->publicPath . '/rollback-theme/app.css'));
            self::assertSame('old-js', file_get_contents($this->publicPath . '/rollback-theme/app.js'));
            self::assertSame([], glob($this->publicPath . '/.rollback-theme.backup-*') ?: []);
        }
    }

    public function testRestorationFailurePreservesBackupForOperatorRecovery(): void
    {
        $publisher = new ThemeAssetPublisher(
            $this->publicPath,
            null,
            static function (string $from, string $to): bool {
                if (str_contains($from, '.staging-')) return false;
                if (str_contains($from, '.backup-')) return false;
                return rename($from, $to);
            },
        );

        try {
            $publisher->publish($this->manifest());
            self::fail('Expected the asset swap and restoration to fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Theme asset publication failed and rollback could not restore the previous assets.', $error->getMessage());
        }

        $backups = glob($this->publicPath . '/.rollback-theme.backup-*') ?: [];
        self::assertCount(1, $backups);
        self::assertFileExists($backups[0] . '/app.css');
        self::assertSame('old-css', file_get_contents($backups[0] . '/app.css'));
    }

    private function manifest(): ThemeManifest
    {
        return ThemeManifest::fromArray([
            'name' => 'Rollback Theme',
            'slug' => 'rollback-theme',
            'version' => '1.0.0',
            'cms_min_version' => '0.4.0-beta.1',
        ], $this->themePath);
    }
}
