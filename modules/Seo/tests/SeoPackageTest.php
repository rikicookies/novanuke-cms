<?php

declare(strict_types=1);

namespace Modules\Seo\Tests;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use Modules\Seo\src\SitemapBuilder;
use NovaNuke\Core\Sitemap\SitemapCollecting;
use PHPUnit\Framework\TestCase;

final class SeoPackageTest extends TestCase
{
    public function testPackageProvidesItsCompleteStandaloneSurface(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode((string) file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('seo', $manifest['slug']);
        self::assertSame('1.1.0', $manifest['version']);
        self::assertSame('Modules\\Seo\\src\\SeoModule', $manifest['provider']);
        self::assertSame(['sitemap.collecting'], $manifest['events']);
        foreach (['README.md', 'src/SeoModule.php', 'src/PublicSeoController.php', 'src/SitemapBuilder.php', 'src/SitemapCollecting.php'] as $file) {
            self::assertFileExists($root . '/' . $file, $file);
        }

        $provider = (string) file_get_contents($root . '/src/SeoModule.php');
        self::assertStringContainsString("'/sitemap.xml'", $provider);
        self::assertStringContainsString("'seo.sitemap'", $provider);
        self::assertStringContainsString("'/robots.txt'", $provider);
        self::assertStringContainsString("'seo.robots'", $provider);
    }

    public function testBuilderDeduplicatesSortsAndEscapesPublicUrls(): void
    {
        $collection = new SitemapCollecting();
        $collection->add('/news/zeta', '2026-09-03 10:00:00', 'weekly', 0.8);
        $collection->add('/news/alpha', null, 'daily', 0.9);
        $collection->add('/news/alpha', null, 'weekly', 0.7);
        $xml = (new SitemapBuilder())->build('https://example.test/community', $collection);
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        self::assertSame(2, $xpath->query('//s:url')->length);
        self::assertSame('https://example.test/community/news/alpha', $xpath->evaluate('string((//s:loc)[1])'));
        self::assertSame('0.7', $xpath->evaluate('string((//s:priority)[1])'));
    }

    public function testBuilderRejectsUnsafeBaseUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SitemapBuilder())->build('javascript:alert(1)', new SitemapCollecting());
    }

    public function testLegacyPayloadNameAliasesTheCoreContract(): void
    {
        self::assertInstanceOf(SitemapCollecting::class, new \Modules\Seo\src\SitemapCollecting());
    }
}
