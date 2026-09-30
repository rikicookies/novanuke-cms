<?php

declare(strict_types=1);
namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class NewsAdminThemeBoundaryTest extends TestCase
{
    public function testNewsAdminUsesItsReservedNamespaceAndCoreLayoutDespitePublicOverrides(): void
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/novalearn-admin-boundary-' . bin2hex(random_bytes(6));
        mkdir($temporary . '/layouts', 0770, true);
        mkdir($temporary . '/admin', 0770, true);
        file_put_contents($temporary . '/layouts/admin.twig', 'PUBLIC_THEME_SHADOW');
        file_put_contents($temporary . '/admin/index.twig', 'PUBLIC_THEME_SHADOW');
        file_put_contents($temporary . '/admin/edit.twig', 'PUBLIC_THEME_SHADOW');
        try {
            $translator = new Translator('en', 'en', $root . '/language');
            $translator->addNamespace('news', $root . '/modules/News/language');
            $views = new ViewRenderer($root . '/resources/views', $temporary . '/cache', false, $translator);
            $views->addNamespace('admin-core', $root . '/resources/views');
            $views->addNamespace('admin-news', $root . '/modules/News/views/admin');
            $views->addNamespace('news', $root . '/modules/News/views');
            $views->prependNamespace('news', $temporary);
            $views->prependPath($temporary);
            foreach (['index.twig', 'edit.twig'] as $name) {
                $html = $views->render('@admin-news/' . $name, [
                    'articles' => [], 'categories' => [], 'topics' => [], 'article' => [],
                    'errors' => [], 'csrf_token' => 'fixture', 'current_user' => null,
                    'cms_name' => 'NovaNuke', 'cms_locale' => 'en',
                ]);
                self::assertStringContainsString('<!doctype html>', $html);
                self::assertStringContainsString('/assets/admin/limitless/', $html);
                self::assertStringNotContainsString('PUBLIC_THEME_SHADOW', $html);
            }
            $provider = file_get_contents($root . '/modules/News/src/NewsModule.php');
            self::assertStringContainsString("addNamespace('admin-news', \$context->basePath . '/views/admin')", $provider);
        } finally {
            foreach (['layouts/admin.twig', 'admin/index.twig', 'admin/edit.twig'] as $name) unlink($temporary . '/' . $name);
            if (is_dir($temporary . '/cache')) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary . '/cache', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                rmdir($temporary . '/cache');
            }
            rmdir($temporary . '/layouts'); rmdir($temporary . '/admin'); rmdir($temporary);
        }
    }
}
