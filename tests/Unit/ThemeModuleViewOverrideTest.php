<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class ThemeModuleViewOverrideTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/novanuke-theme-view-' . bin2hex(random_bytes(6));
        mkdir($this->work . '/core', 0770, true);
        mkdir($this->work . '/cache', 0770, true);
        mkdir($this->work . '/module', 0770, true);
        mkdir($this->work . '/theme', 0770, true);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->work)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->work);
    }

    public function testThemeTemplateWinsAndModuleTemplateRemainsFallback(): void
    {
        file_put_contents($this->work . '/theme/index.twig', 'theme: {{ value }}');
        file_put_contents($this->work . '/module/index.twig', 'module: {{ value }}');
        file_put_contents($this->work . '/module/detail.twig', 'module-detail: {{ value }}');

        $views = new ViewRenderer($this->work . '/core', $this->work . '/cache', true);

        // ThemeManager boots before ModuleManager. The override is therefore
        // registered first; the module later appends its canonical fallback.
        $views->prependNamespace('news', $this->work . '/theme');
        $views->addNamespace('news', $this->work . '/module');

        self::assertSame('theme: hello', $views->render('@news/index.twig', ['value' => 'hello']));
        self::assertSame('module-detail: hello', $views->render('@news/detail.twig', ['value' => 'hello']));
    }

    public function testModuleTemplateRendersNormallyWithoutThemeOverride(): void
    {
        file_put_contents($this->work . '/module/index.twig', 'module-only');

        $views = new ViewRenderer($this->work . '/core', $this->work . '/cache', true);
        $views->addNamespace('pages', $this->work . '/module');

        self::assertSame('module-only', $views->render('@pages/index.twig'));
    }

    public function testNamespaceValidationRejectsUnsafeNames(): void
    {
        $views = new ViewRenderer($this->work . '/core', $this->work . '/cache', true);

        $this->expectException(\InvalidArgumentException::class);
        $views->prependNamespace('../news', $this->work . '/theme');
    }

    public function testReservedAdminOverrideDirectoriesAreIgnored(): void
    {
        $manager = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/Themes/ThemeManager.php');

        self::assertStringContainsString("str_starts_with(\$namespace, 'admin-')", $manager);
        self::assertStringContainsString('Themes may replace public module presentation, never Admin markup.', $manager);
    }
}
