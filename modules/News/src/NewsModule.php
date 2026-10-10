<?php

declare(strict_types=1);

namespace Modules\News\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Content\ContentRendererInterface;
use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Core\Comments\CommentTargetChecking;
use NovaNuke\Core\Search\SearchProvidersRegistering;
use NovaNuke\Core\Sitemap\SitemapCollecting;
use NovaNuke\Core\Media\MediaLibraryInterface;
use NovaNuke\Core\Media\MediaUsageChecking;

final class NewsModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->get(ViewRenderer::class)->addNamespace('news', $context->basePath . '/views');
        $context->container->get(ViewRenderer::class)->addNamespace('admin-news', $context->basePath . '/views/admin');
        $context->container->bind(NewsRepository::class, static fn (Container $container) => new NewsRepository(
            $container->get(\PDO::class),
            $container->get(\NovaNuke\Core\Access\AccessAudience::class),
            $container->get(\NovaNuke\Core\Settings\SettingsRepository::class)->integer('site.per_page', 10, 5, 100),
        ));
        $context->container->bind(NewsInput::class, static fn () => new NewsInput());
        $context->container->get(ViewRenderer::class)->addGlobal('news_rss_url', '/news/rss.xml');
    }

    public function boot(ModuleContext $context): void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::PROFILE_STATISTICS_BUILDING,static function(object$event)use($context):void{if($event instanceof \NovaNuke\Core\Profile\ProfileStatisticsBuilding)$event->add('Published news',$context->container->get(NewsRepository::class)->publishedCountByAuthor($event->profileId));});
        $context->events->listen(\NovaNuke\Core\Events\EventName::SEARCH_PROVIDERS_REGISTERING, static function (object $event) use ($context): void {
            if ($event instanceof SearchProvidersRegistering) $event->registry->add(new NewsSearchProvider(
                $context->container->get(\PDO::class),
                $context->container->get(\NovaNuke\Core\Access\AccessAudience::class),
            ));
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::SITEMAP_COLLECTING, static function (object $event) use ($context): void {
            if (! $event instanceof SitemapCollecting) return;
            $event->add('/news', null, 'daily', 0.9);
            foreach ($context->container->get(NewsRepository::class)->sitemapEntries() as $article) {
                $event->add('/news/' . $article['slug'], $article['updated_at'], 'weekly', 0.8);
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING, static function (object $event): void {
            if ($event instanceof AdminMenuBuilding) $event->add('News', '/admin/news', 'news.edit');
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::MEDIA_USAGE_CHECKING, static function (object $event) use ($context): void {
            if ($event instanceof MediaUsageChecking) $event->add('news.featured-image', $context->container->get(NewsRepository::class)->mediaUsage($event->publicPath));
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::COMMENTS_CONTENT_CHECKING, static function (object $event) use ($context): void {
            $user = $context->container->get(\NovaNuke\Auth\AuthManager::class)->user();
            if ($event instanceof CommentTargetChecking && $event->type === 'news'
                && $context->container->get(NewsRepository::class)->acceptsComments($event->contentId, $user ? (int) $user['id'] : null)) {
                $event->accept();
            }
        });
        $public = static fn (Container $container): PublicNewsController => new PublicNewsController(
            $container->get(NewsRepository::class), $container->get(SessionManager::class), $container->get(ViewRenderer::class),
            $container->get(ContentRendererInterface::class),
            $container->has(CommentProviderInterface::class) ? $container->get(CommentProviderInterface::class) : null,
            $container->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $container->get(\NovaNuke\Auth\AuthManager::class),
            $container->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $container->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $admin = static fn (Container $container): AdminNewsController => new AdminNewsController(
            $container->get(NewsRepository::class), $container->get(NewsInput::class),
            $container->get(\NovaNuke\Auth\AuthManager::class), $container->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $container->get(\NovaNuke\Core\Logging\ActivityLogger::class), $container->get(\NovaNuke\Core\Events\EventDispatcher::class),
            $container->get(\NovaNuke\Core\Security\CsrfTokenManager::class), $container->get(SessionManager::class),
            $container->get(ViewRenderer::class),
            $container->has(MediaLibraryInterface::class) ? $container->get(MediaLibraryInterface::class) : null,
            $container->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $rss = static fn (Container $container): RssController => new RssController(
            $container->get(NewsRepository::class), new RssFeedBuilder(),
            $container->get(\NovaNuke\Core\Settings\SettingsRepository::class),
            $container->get(\NovaNuke\Core\Config\ConfigRepository::class),
            $container->get(ContentRendererInterface::class),
        );
        $context->router->get('/news', static fn (Request $request, Container $container): Response => $public($container)->index($request), 'news.index');
        $context->router->get('/news/category/{slug}', static fn (Request $request, Container $container): Response => $public($container)->index($request, (string) $request->attribute('slug')), 'news.category');
        $context->router->get('/news/rss.xml', static fn (Request $request, Container $container): Response => $rss($container)->feed(), 'news.rss');
        $context->router->get('/news/{slug}', static fn (Request $request, Container $container): Response => $public($container)->show($request), 'news.show');
        $context->router->get('/admin/news', static fn (Request $request, Container $container): Response => $admin($container)->index($request));
        $context->router->get('/admin/news/new', static fn (Request $request, Container $container): Response => $admin($container)->create());
        $context->router->get('/admin/news/{id}/edit', static fn (Request $request, Container $container): Response => $admin($container)->edit($request));
        $context->router->post('/admin/news/bulk', static fn (Request $request, Container $container): Response => $admin($container)->bulk($request));
        $context->router->post('/admin/news/save', static fn (Request $request, Container $container): Response => $admin($container)->save($request));
        $context->router->post('/admin/news/{id}/delete', static fn (Request $request, Container $container): Response => $admin($container)->delete($request));
        $context->router->post('/admin/news/taxonomy/{type}', static fn (Request $request, Container $container): Response => $admin($container)->taxonomy($request));
        $context->router->get('/admin/news/taxonomy/{type}/{id}/edit', static fn (Request $request, Container $container): Response => $admin($container)->taxonomyEdit($request));
        $context->router->post('/admin/news/taxonomy/{type}/{id}/update', static fn (Request $request, Container $container): Response => $admin($container)->taxonomyUpdate($request));
        $context->router->get('/admin/news/taxonomy/{type}/{id}/delete', static fn (Request $request, Container $container): Response => $admin($container)->taxonomyDeleteConfirm($request));
        $context->router->post('/admin/news/taxonomy/{type}/{id}/delete', static fn (Request $request, Container $container): Response => $admin($container)->taxonomyDelete($request));
    }
}
