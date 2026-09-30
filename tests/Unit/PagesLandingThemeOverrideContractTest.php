<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class PagesLandingThemeOverrideContractTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/novanuke-pages-landing-' . bin2hex(random_bytes(6));
        foreach (['core', 'cache', 'module', 'theme'] as $directory) {
            mkdir($this->work . '/' . $directory, 0770, true);
        }
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->work)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->work);
    }

    public function testPagesLandingCanBeCompletelyReplacedByThemeMarkup(): void
    {
        file_put_contents($this->work . '/theme/landing.twig', '<main data-theme-landing>{{ page.title }}|{{ page.content_html }}</main>');
        file_put_contents($this->work . '/module/landing.twig', '<article data-module-landing>{{ page.title }}</article>');

        $views = new ViewRenderer($this->work . '/core', $this->work . '/cache', true);
        $views->prependNamespace('pages', $this->work . '/theme');
        $views->addNamespace('pages', $this->work . '/module');

        $html = $views->render('@pages/landing.twig', [
            'page' => ['title' => 'Theme landing', 'content_html' => 'Rendered content'],
        ]);

        self::assertStringContainsString('data-theme-landing', $html);
        self::assertStringContainsString('Theme landing|Rendered content', $html);
        self::assertStringNotContainsString('data-module-landing', $html);
    }

    public function testPagesLandingFallsBackToModuleWhenThemeDoesNotProvideIt(): void
    {
        file_put_contents($this->work . '/module/landing.twig', '<article data-module-landing>{{ page.title }}</article>');

        $views = new ViewRenderer($this->work . '/core', $this->work . '/cache', true);
        $views->prependNamespace('pages', $this->work . '/theme');
        $views->addNamespace('pages', $this->work . '/module');

        $html = $views->render('@pages/landing.twig', ['page' => ['title' => 'Fallback landing']]);

        self::assertStringContainsString('data-module-landing', $html);
        self::assertStringContainsString('Fallback landing', $html);
    }

    public function testPublicPagesControllerKeepsLandingSelectionAndViewContract(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/Pages/src/PublicPagesController.php');

        self::assertStringContainsString("['default', 'landing']", $source);
        self::assertStringContainsString("'page' => \$page", $source);
        self::assertStringContainsString("'edit_url' => \$editUrl", $source);
        self::assertStringContainsString("'delete_url' =>", $source);
        self::assertStringContainsString("'content_csrf_token' =>", $source);
        self::assertStringContainsString("'comments_available' =>", $source);
        self::assertStringContainsString("'@pages/' . \$template . '.twig'", $source);
        self::assertStringNotContainsString('novamodern', strtolower($source));
        self::assertStringNotContainsString('themes/', strtolower($source));
    }

    public function testPagesAdminRemainsOnReservedNamespace(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/Pages/src/AdminPagesController.php');

        self::assertStringContainsString('@admin-pages/', $source);
        self::assertStringNotContainsString('@pages/admin/', $source);
    }
}
