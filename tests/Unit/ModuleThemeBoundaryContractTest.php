<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleThemeBoundaryContractTest extends TestCase
{
    #[DataProvider('publicModuleProvider')]
    public function testPublicControllersUseThemeOverrideableModuleNamespaces(string $module, string $slug): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/modules/' . $module . '/src/Public*Controller.php') ?: [];
        self::assertNotEmpty($files, $module . ' must expose a public controller for this contract.');

        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
        self::assertStringContainsString("@{$slug}/", $source);
        self::assertStringNotContainsString('themes/', $source);
        self::assertStringNotContainsString('novamodern', strtolower($source));
    }

    #[DataProvider('adminModuleProvider')]
    public function testAdminControllersUseReservedNonThemeNamespace(string $module, string $slug): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/modules/' . $module . '/src/Admin*Controller.php') ?: [];
        self::assertNotEmpty($files);
        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));

        self::assertStringContainsString("@admin-{$slug}/", $source);
        self::assertStringNotContainsString("@{$slug}/admin/", $source);
    }

    /** @return iterable<string, array{string,string}> */
    public static function publicModuleProvider(): iterable
    {
        yield 'downloads' => ['Downloads', 'downloads'];
        yield 'news' => ['News', 'news'];
        yield 'pages' => ['Pages', 'pages'];
        yield 'search' => ['Search', 'search'];
        yield 'web links' => ['WebLinks', 'web-links'];
        yield 'friends' => ['Friends', 'friends'];
    }

    /** @return iterable<string, array{string,string}> */
    public static function adminModuleProvider(): iterable
    {
        yield 'comments' => ['Comments', 'comments'];
        yield 'downloads' => ['Downloads', 'downloads'];
        yield 'news' => ['News', 'news'];
        yield 'pages' => ['Pages', 'pages'];
        yield 'search' => ['Search', 'search'];
        yield 'web links' => ['WebLinks', 'web-links'];
    }
}
