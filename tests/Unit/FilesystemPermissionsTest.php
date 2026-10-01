<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Storage\FilesystemPermissions;
use NovaNuke\Core\System\ReleaseArchiveBuilder;
use NovaNuke\Installer\EnvWriter;
use NovaNuke\Installer\StorageProvisioner;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class FilesystemPermissionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/novanuke-permissions-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->root)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($this->root);
    }

    public function testReleaseArchiveEncodesTraversableDirectoriesAndNonExecutableFiles(): void
    {
        if (! class_exists(ZipArchive::class)) self::markTestSkipped('PHP ZIP extension is unavailable.');
        mkdir($this->root . '/modules/Search/database/migrations', 0755, true);
        mkdir($this->root . '/public/uploads/media/2026/10', 0755, true);
        chmod($this->root . '/modules/Search/database', 0750);
        chmod($this->root . '/public/uploads/media', 0750);
        mkdir($this->root . '/storage/private/backups', 0700, true);
        mkdir($this->root . '/public/assets/themes/novamodern', 0755, true);
        mkdir($this->root . '/.github', 0755, true);
        file_put_contents($this->root . '/modules/Search/database/migrations/example.php', '<?php');
        file_put_contents($this->root . '/public/uploads/.htaccess', 'deny');
        file_put_contents($this->root . '/public/uploads/media/2026/10/image.jpg', 'runtime');
        file_put_contents($this->root . '/storage/private/.htaccess', 'deny');
        file_put_contents($this->root . '/storage/private/backups/.gitkeep', '');
        file_put_contents($this->root . '/public/assets/themes/novamodern/generated.css', 'generated');
        file_put_contents($this->root . '/.github/workflow.yml', 'development');
        file_put_contents($this->root . '/ECC-AUDIT.md', 'development report');
        file_put_contents($this->root . '/README.md', 'release');
        chmod($this->root . '/modules/Search/database/migrations/example.php', 0600);

        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0750, $this->permissionBits($this->root . '/modules/Search/database'));
            self::assertSame(0600, $this->permissionBits($this->root . '/modules/Search/database/migrations/example.php'));
        }

        $archive = $this->root . '/out/release.zip';
        $count = (new ReleaseArchiveBuilder())->build($this->root, $archive);
        self::assertGreaterThan(0, $count);

        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($archive));
        try {
            self::assertNotFalse($zip->locateName('modules/Search/database/migrations/example.php'));
            self::assertNotFalse($zip->locateName('public/uploads/.htaccess'));
            self::assertNotFalse($zip->locateName('storage/private/.htaccess'));
            self::assertNotFalse($zip->locateName('storage/private/backups/.gitkeep'));
            self::assertFalse($zip->locateName('public/uploads/media/2026/10/image.jpg') !== false);
            self::assertFalse($zip->locateName('public/assets/themes/novamodern/generated.css') !== false);
            self::assertFalse($zip->locateName('.github/workflow.yml') !== false);
            self::assertFalse($zip->locateName('ECC-AUDIT.md') !== false);
            self::assertSame(FilesystemPermissions::PUBLIC_DIRECTORY, $this->mode($zip, 'modules/Search/database'));
            self::assertSame(FilesystemPermissions::PUBLIC_FILE, $this->mode($zip, 'modules/Search/database/migrations/example.php'));
            self::assertSame(FilesystemPermissions::PUBLIC_FILE, $this->mode($zip, 'README.md'));
        } finally {
            $zip->close();
        }
    }

    public function testInstallerUsesPublicUploadsAndPrivateRuntimePolicy(): void
    {
        (new StorageProvisioner())->provision($this->root);
        self::assertDirectoryIsWritable($this->root . '/public/uploads');
        self::assertDirectoryIsWritable($this->root . '/storage/private');
        self::assertSame(0755, FilesystemPermissions::PUBLIC_DIRECTORY);
        self::assertSame(0700, FilesystemPermissions::PRIVATE_DIRECTORY);
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0755, $this->permissionBits($this->root . '/public/uploads'));
            self::assertSame(0700, $this->permissionBits($this->root . '/storage/private'));
        }
    }

    public function testEnvironmentWriterRemainsOwnerOnly(): void
    {
        $path = $this->root . '/.env';
        (new EnvWriter())->write($path, ['APP_KEY' => 'secret']);
        self::assertFileExists($path);
        if (PHP_OS_FAMILY !== 'Windows') self::assertSame(0600, $this->permissionBits($path));
    }

    public function testPublicAndPrivateFileModesAreExplicit(): void
    {
        $public = $this->root . '/public/uploads/image.jpg';
        $private = $this->root . '/storage/private/download.bin';
        mkdir(dirname($public), 0755, true);
        mkdir(dirname($private), 0700, true);
        file_put_contents($public, 'public');
        file_put_contents($private, 'private');

        FilesystemPermissions::setFileMode($public, FilesystemPermissions::PUBLIC_FILE);
        FilesystemPermissions::setFileMode($private, FilesystemPermissions::PRIVATE_FILE);
        self::assertFileExists($public);
        self::assertFileExists($private);
        self::assertSame(0644, FilesystemPermissions::PUBLIC_FILE);
        self::assertSame(0600, FilesystemPermissions::PRIVATE_FILE);

        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0644, $this->permissionBits($public));
            self::assertSame(0600, $this->permissionBits($private));
        }
    }

    public function testPublicUploadGuardStillBlocksExecutableContent(): void
    {
        $guard = (string) file_get_contents(dirname(__DIR__, 2) . '/public/uploads/.htaccess');
        self::assertStringContainsString('Options -Indexes -ExecCGI', $guard);
        self::assertStringContainsString('php', strtolower($guard));
        self::assertStringContainsString('RemoveHandler', $guard);
    }

    private function mode(ZipArchive $zip, string $name): int
    {
        $index = $zip->locateName($name);
        if ($index === false) $index = $zip->locateName(rtrim($name, '/') . '/');
        self::assertNotFalse($index);
        $opsys = 0;
        $attributes = 0;
        self::assertTrue($zip->getExternalAttributesIndex((int) $index, $opsys, $attributes));
        self::assertSame(ZipArchive::OPSYS_UNIX, $opsys);
        return ($attributes >> 16) & 07777;
    }

    private function permissionBits(string $path): int
    {
        return fileperms($path) & 07777;
    }
}
