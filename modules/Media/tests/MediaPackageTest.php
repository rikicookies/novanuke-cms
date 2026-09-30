<?php

declare(strict_types=1);

namespace Modules\Media\Tests;

use Modules\Media\src\MediaRepository;
use Modules\Media\src\MediaUploadValidator;
use NovaNuke\Core\Media\MediaLibraryInterface;
use NovaNuke\Core\Media\MediaUsageChecking;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaPackageTest extends TestCase
{
    private string $image;

    protected function setUp(): void
    {
        $this->image = tempnam(sys_get_temp_dir(), 'novanuke-media-');
        file_put_contents($this->image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKklEQVR4nGMwTptJU8QwasGoBaMWjFowasGoBaMWjFowasGoBaMWDBULAKbkyD3xKY9xAAAAAElFTkSuQmCC', true));
    }

    protected function tearDown(): void
    {
        if (is_file($this->image)) unlink($this->image);
    }

    public function testPackageProvidesItsCompleteStandaloneSurface(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode((string) file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('media', $manifest['slug']);
        self::assertSame('1.2.0', $manifest['version']);
        self::assertSame('Modules\\Media\\src\\MediaModule', $manifest['provider']);
        self::assertSame(['media.manage'], $manifest['permissions']);
        self::assertTrue(is_a(MediaRepository::class, MediaLibraryInterface::class, true));
        foreach (['README.md', 'language/en.json', 'language/es.json', 'views/admin/index.twig', 'database/migrations/2026_09_03_000001_create_media_files_table.php'] as $file) {
            self::assertFileExists($root . '/' . $file, $file);
        }
    }

    public function testCataloguesMatchAndAdminUsesModuleOwnedNavigation(): void
    {
        $root = dirname(__DIR__);
        $en = json_decode((string) file_get_contents($root . '/language/en.json'), true, 128, JSON_THROW_ON_ERROR);
        $es = json_decode((string) file_get_contents($root . '/language/es.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($en), array_keys($es));
        foreach (['message.uploaded', 'message.updated', 'message.deleted', 'error.in_use', 'error.image_invalid'] as $key) self::assertArrayHasKey($key, $en);
        $provider = (string) file_get_contents($root . '/src/MediaModule.php');
        self::assertStringContainsString("'media::title','/admin/media','media.manage','media','content'", $provider);
    }

    public function testItAcceptsVerifiedPngContentAndRejectsExtensionMismatch(): void
    {
        $validator = new MediaUploadValidator();
        $media = $validator->validate(['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->image, 'size' => filesize($this->image), 'name' => 'photo.png']);
        self::assertSame('image/png', $media->mimeType);
        self::assertSame(32, $media->width);
        self::assertSame(32, $media->height);

        $this->expectException(RuntimeException::class);
        $validator->validate(['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->image, 'size' => filesize($this->image), 'name' => 'photo.jpg']);
    }

    public function testLegacyUsagePayloadAliasesTheCoreContract(): void
    {
        $usage = new \Modules\Media\src\MediaUsageChecking('/uploads/media/2026/09/a.png');
        self::assertInstanceOf(MediaUsageChecking::class, $usage);
        $usage->add('news.featured-image', 2);
        self::assertSame(2, $usage->total());
    }
}
