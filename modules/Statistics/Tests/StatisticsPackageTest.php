<?php

declare(strict_types=1);

namespace Modules\Statistics\Tests;

use Modules\Statistics\src\StatisticsDimensions;
use NovaNuke\Core\ModuleApi;
use NovaNuke\Core\Modules\ModuleManifest;
use PHPUnit\Framework\TestCase;

final class StatisticsPackageTest extends TestCase
{
    public function testPackageProvidesItsCompleteModuleSurface(): void
    {
        $module = dirname(__DIR__);
        $manifest = ModuleManifest::fromArray(
            json_decode((string) file_get_contents($module . '/module.json'), true, 32, JSON_THROW_ON_ERROR),
            $module,
        );
        self::assertSame('statistics', $manifest->slug);
        self::assertSame('1.3.0', $manifest->version);
        self::assertSame(ModuleApi::VERSION, $manifest->apiVersion);
        self::assertSame([], $manifest->dependencies);
        self::assertSame('statistics.public_enabled', $manifest->navigation['enabled_setting']);
        foreach ([
            'database/migrations/2026_09_09_000001_create_statistics_tables.php',
            'database/migrations/2026_09_09_000002_restore_statistics_block.php',
            'src/StatisticsModule.php', 'src/StatisticsRepository.php', 'src/StatisticsTracker.php',
            'src/StatisticsDimensions.php', 'src/PublicStatisticsController.php', 'src/AdminStatisticsController.php',
            'views/index.twig', 'views/unavailable.twig', 'views/block.twig',
            'views/admin/index.twig', 'views/admin/table.twig',
            'language/en.json', 'language/es.json', 'README.md',
        ] as $file) self::assertFileExists($module . '/' . $file, $file);
    }

    public function testProviderOwnsAdminPresentationBlocksAndMaintenance(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/StatisticsModule.php');
        self::assertStringContainsString("'statistics.view-admin','chart','overview'", $source);
        self::assertStringContainsString('EventName::BLOCK_RENDERING', $source);
        self::assertStringContainsString('EventName::MAINTENANCE_PRUNING', $source);
        self::assertStringContainsString("'statistics-summary'", $source);
        self::assertDoesNotMatchRegularExpression("/(?:dispatch|listen)\\(\\s*['\"][a-z][a-z0-9.-]+['\"]/", $source);
    }

    public function testDimensionsDoNotRetainSensitivePathsOrFullAgents(): void
    {
        $dimensions = new StatisticsDimensions();
        $result = $dimensions->classify('/news/private-slug?secret=1', 'https://www.google.com/search?q=private', 'Mozilla/5.0 (iPhone) AppleWebKit Safari/605.1', 'novanuke.test');
        self::assertSame('news', $result['section']);
        self::assertSame('google.com', $result['referrer']);
        self::assertSame('safari', $result['browser']);
        self::assertSame('mobile', $result['device']);
        self::assertArrayNotHasKey('path', $result);
        self::assertSame('direct', $dimensions->classify('/', 'https://novanuke.test/news', 'Chrome', 'novanuke.test')['referrer']);
        self::assertSame('other', $dimensions->classify('/unknown', 'http://192.168.1.20/private', 'Firefox', 'novanuke.test')['referrer']);
    }

    public function testCataloguesCoverEveryLiteralViewKey(): void
    {
        $module = dirname(__DIR__);
        $en = json_decode((string) file_get_contents($module . '/language/en.json'), true, 512, JSON_THROW_ON_ERROR);
        $es = json_decode((string) file_get_contents($module . '/language/es.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($en), array_keys($es));
        foreach (['views/index.twig', 'views/unavailable.twig', 'views/block.twig', 'views/admin/index.twig', 'views/admin/table.twig'] as $file) {
            $source = (string) file_get_contents($module . '/' . $file);
            preg_match_all("/trans\\('statistics::([a-z0-9_.-]+)'/", $source, $matches);
            foreach ($matches[1] as $key) {
                if (! str_ends_with($key, '.')) self::assertArrayHasKey($key, $en, $file . ': ' . $key);
            }
        }
        foreach (['message.saved', 'error.csrf', 'error.forbidden'] as $key) self::assertArrayHasKey($key, $en, $key);
    }

    public function testUnavailableResponseUsesThePublicTheme(): void
    {
        $module = dirname(__DIR__);
        $controller = (string) file_get_contents($module . '/src/PublicStatisticsController.php');
        $view = (string) file_get_contents($module . '/views/unavailable.twig');
        self::assertStringContainsString("render('@statistics/unavailable.twig')", $controller);
        self::assertStringNotContainsString("Response::html('Statistics are not public.'", $controller);
        self::assertStringContainsString('{% extends "layouts/default.twig" %}', $view);
        self::assertStringContainsString("trans('statistics::unavailable.title')", $view);
    }
}
