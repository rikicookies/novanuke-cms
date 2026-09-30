<?php

declare(strict_types=1);

namespace Modules\Seo\src;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;

final class SeoModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->bind(SitemapBuilder::class, static fn () => new SitemapBuilder());
    }

    public function boot(ModuleContext $context): void
    {
        $controller = static fn (Container $c) => new PublicSeoController(
            $c->get(\NovaNuke\Core\Events\EventDispatcher::class), $c->get(SitemapBuilder::class),
            $c->get(\NovaNuke\Core\Settings\SettingsRepository::class)->string('site.url', 'http://localhost'),
        );
        $context->router->get('/sitemap.xml', static fn (Request $r, Container $c): Response => $controller($c)->sitemap(), 'seo.sitemap');
        $context->router->get('/robots.txt', static fn (Request $r, Container $c): Response => $controller($c)->robots(), 'seo.robots');
    }
}
