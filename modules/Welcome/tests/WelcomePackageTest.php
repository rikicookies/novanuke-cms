<?php

declare(strict_types=1);

namespace Modules\Welcome\Tests;

use NovaNuke\Core\ModuleApi;
use NovaNuke\Core\Modules\ModuleManifest;
use PHPUnit\Framework\TestCase;

final class WelcomePackageTest extends TestCase
{
    public function testPackageProvidesItsCompleteModuleSurface(): void
    {
        $module = dirname(__DIR__);
        $manifest = ModuleManifest::fromArray(
            json_decode((string) file_get_contents($module . '/module.json'), true, 32, JSON_THROW_ON_ERROR),
            $module,
        );

        self::assertSame('welcome', $manifest->slug);
        self::assertSame('1.1.0', $manifest->version);
        self::assertSame(ModuleApi::VERSION, $manifest->apiVersion);
        self::assertSame([], $manifest->dependencies);
        self::assertSame('/welcome', $manifest->navigation['url']);
        self::assertTrue($manifest->navigation['public_landing']);
        self::assertContains('welcome.view', $manifest->permissions);
        self::assertContains('welcome.manage', $manifest->permissions);
        foreach ([
            'database/migrations/2026_09_01_000001_create_welcome_messages_table.php',
            'src/WelcomeModule.php',
            'src/WelcomeContentResolver.php',
            'src/AdminWelcomeController.php',
            'src/WelcomePageEvent.php',
            'views/index.twig',
            'views/admin/index.twig',
            'language/en.json',
            'language/es.json',
            'README.md',
        ] as $file) self::assertFileExists($module . '/' . $file, $file);
    }

    public function testDynamicLinksOnlyUseEnabledModuleInventory(): void
    {
        $module = dirname(__DIR__);
        $provider = (string) file_get_contents($module . '/src/WelcomeModule.php');
        $view = (string) file_get_contents($module . '/views/index.twig');

        self::assertStringContainsString('ModuleManager::class', $provider);
        self::assertStringContainsString("['enabled']", $provider);
        self::assertStringContainsString("'links' => \$links", $provider);
        foreach (['href="/news"', 'href="/downloads"', 'href="/wiki"', 'href="/links"'] as $hardcoded) {
            self::assertStringNotContainsString($hardcoded, $view);
        }
        self::assertStringContainsString('href="{{ link.url }}"', $view);
        self::assertStringContainsString('{% if links %}', $view);
        self::assertStringContainsString("'content' => \$content", $provider);
    }

    public function testCataloguesMatchAndViewsUseExistingKeys(): void
    {
        $module = dirname(__DIR__);
        $english = json_decode((string) file_get_contents($module . '/language/en.json'), true, 128, JSON_THROW_ON_ERROR);
        $spanish = json_decode((string) file_get_contents($module . '/language/es.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($english), array_keys($spanish));

        $source = (string) file_get_contents($module . '/views/index.twig');
        preg_match_all("/trans\\('welcome::([a-z0-9_.-]+)'/", $source, $matches);
        foreach ($matches[1] as $key) {
            if (! str_ends_with($key, '.')) self::assertArrayHasKey($key, $english, $key);
        }
        self::assertStringNotContainsString('|raw', $source);
        self::assertStringContainsString('welcome.manage', (string) file_get_contents($module . '/module.json'));
    }

    public function testAdminContractDeclaresProtectionAndSafePersistence(): void
    {
        $module = dirname(__DIR__);
        $controller = (string) file_get_contents($module . '/src/AdminWelcomeController.php');
        $manager = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Core/Modules/ModuleManager.php');

        self::assertStringContainsString("'welcome.manage'", $controller);
        self::assertStringContainsString('$this->csrf->validate', $controller);
        self::assertStringContainsString('$this->settings->setMany', $controller);
        self::assertStringContainsString("'changed_fields'", $controller);
        self::assertStringContainsString('$this->registerPermissions($manifest);', $manager);
    }
}
