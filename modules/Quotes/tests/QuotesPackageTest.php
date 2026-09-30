<?php

declare(strict_types=1);

namespace Modules\Quotes\Tests;

use NovaNuke\Core\ModuleApi;
use NovaNuke\Core\Modules\ModuleManifest;
use PHPUnit\Framework\TestCase;

final class QuotesPackageTest extends TestCase
{
    public function testPackageProvidesItsCompleteModuleApiSurface(): void
    {
        $module = dirname(__DIR__);
        $manifest = ModuleManifest::fromArray(
            json_decode((string) file_get_contents($module . '/module.json'), true, 32, JSON_THROW_ON_ERROR),
            $module,
        );

        self::assertSame('quotes', $manifest->slug);
        self::assertSame('1.0.1', $manifest->version);
        self::assertSame(ModuleApi::VERSION, $manifest->apiVersion);
        self::assertSame([], $manifest->dependencies);
        self::assertContains('quotes.manage', $manifest->permissions);
        foreach ([
            'database/migrations/2026_09_10_000001_create_quotes_table.php',
            'src/QuoteRepository.php',
            'src/QuotesController.php',
            'src/QuotesModule.php',
            'views/index.twig',
            'views/admin/index.twig',
            'language/en.json',
            'language/es.json',
            'README.md',
        ] as $file) self::assertFileExists($module . '/' . $file, $file);
    }

    public function testProviderUsesNamespacedRoutesAndCoreContractsOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/QuotesModule.php');
        self::assertStringContainsString('EventName::ADMIN_MENU_BUILDING', $source);
        self::assertStringContainsString("'quotes.manage'", $source);
        foreach (['quotes.index', 'quotes.admin', 'quotes.create', 'quotes.delete'] as $route) {
            self::assertStringContainsString("'{$route}'", $source);
        }
        self::assertStringNotContainsString('Modules\\Comments\\', $source);
        self::assertStringNotContainsString('Modules\\Search\\', $source);
    }

    public function testAdminWritesAreProtectedAndCataloguesMatch(): void
    {
        $module = dirname(__DIR__);
        $source = (string) file_get_contents($module . '/src/QuotesController.php');
        self::assertStringContainsString("'quotes.manage'", $source);
        self::assertStringContainsString('$this->csrf->validate', $source);
        self::assertStringContainsString('ActivityLogger', $source);
        self::assertStringContainsString("Response::redirect('/admin/quotes', 303)", $source);

        $english = json_decode((string) file_get_contents($module . '/language/en.json'), true, 128, JSON_THROW_ON_ERROR);
        $spanish = json_decode((string) file_get_contents($module . '/language/es.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($english), array_keys($spanish));
        foreach (glob($module . '/views/*.twig') ?: [] as $view) $this->assertViewKeysExist($view, $english);
        foreach (glob($module . '/views/admin/*.twig') ?: [] as $view) $this->assertViewKeysExist($view, $english);
    }

    /** @param array<string,mixed> $catalogue */
    private function assertViewKeysExist(string $view, array $catalogue): void
    {
        $source = (string) file_get_contents($view);
        preg_match_all("/trans\\('quotes::([a-z0-9_.-]+)'/", $source, $matches);
        foreach ($matches[1] as $key) {
            if (! str_ends_with($key, '.')) self::assertArrayHasKey($key, $catalogue, $view . ': ' . $key);
        }
    }
}
