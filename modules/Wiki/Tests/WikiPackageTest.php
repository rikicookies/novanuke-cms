<?php

declare(strict_types=1);

namespace Modules\Wiki\Tests;

use PHPUnit\Framework\TestCase;

final class WikiPackageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testPackageProvidesItsCompleteStandaloneSurface(): void
    {
        $manifest = json_decode((string) file_get_contents($this->root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('wiki', $manifest['slug']);
        self::assertSame('2.4.0', $manifest['version']);
        self::assertSame('Modules\\Wiki\\src\\WikiModule', $manifest['provider']);
        self::assertSame(['wiki.edit', 'wiki.publish'], $manifest['permissions']);

        foreach (['README.md', 'language/en.json', 'language/es.json'] as $file) {
            self::assertFileExists($this->root . '/' . $file, $file);
        }
        foreach (['wiki-editor.js', 'wiki-folder-import.js', 'wiki-bulk-actions.js', 'wiki.css'] as $file) {
            self::assertFileExists($this->root . '/assets/' . $file, $file);
        }
        foreach (['WikiModule', 'WikiRepository', 'PublicWikiController', 'AdminWikiController', 'WikiPageChanged', 'WikiMarkdownRenderer'] as $class) {
            self::assertFileExists($this->root . '/src/' . $class . '.php', $class);
        }
        self::assertFileExists($this->root . '/views/admin/namespaces.twig');
        self::assertFileExists($this->root . '/database/migrations/2026_10_08_000005_create_wiki_path_aliases.php');
        self::assertCount(5, glob($this->root . '/database/migrations/*.php') ?: []);
    }

    public function testCataloguesMatchAndViewsOnlyUseDeclaredKeys(): void
    {
        $en = json_decode((string) file_get_contents($this->root . '/language/en.json'), true, 128, JSON_THROW_ON_ERROR);
        $es = json_decode((string) file_get_contents($this->root . '/language/es.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($en), array_keys($es));

        foreach (glob($this->root . '/views/*.twig') ?: [] as $view) $this->assertViewKeys($view, $en);
        foreach (glob($this->root . '/views/admin/*.twig') ?: [] as $view) $this->assertViewKeys($view, $en);
    }

    public function testRoutesAssetsAndPrivateStorageRemainModuleOwned(): void
    {
        $source = (string) file_get_contents($this->root . '/src/WikiModule.php');
        foreach (['wiki.index', 'wiki.search', 'wiki.asset.styles', 'wiki.asset.editor', 'wiki.asset.folder-import', 'wiki.asset.bulk-actions'] as $route) {
            self::assertStringContainsString("'{$route}'", $source, $route);
        }
        self::assertStringContainsString("NOVANUKE_ROOT . '/storage/private/wiki'", $source);
        self::assertStringContainsString("EventName::SEARCH_PROVIDERS_REGISTERING", $source);
        self::assertStringContainsString("EventName::SITEMAP_COLLECTING", $source);
        self::assertStringContainsString("'wiki', 'content'", $source);
    }

    /** @param array<string,mixed> $catalogue */
    private function assertViewKeys(string $view, array $catalogue): void
    {
        $source = (string) file_get_contents($view);
        preg_match_all("/trans\\('wiki::([a-z0-9_.-]+)'/", $source, $matches);
        foreach ($matches[1] as $key) self::assertArrayHasKey($key, $catalogue, basename($view) . ': ' . $key);
    }
}
