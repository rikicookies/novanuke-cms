<?php

declare(strict_types=1);

namespace Modules\Pages\src;

use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Core\Comments\CommentTargetChecking;
use NovaNuke\Core\Search\SearchProvidersRegistering;
use NovaNuke\Core\Sitemap\SitemapCollecting;
use NovaNuke\Core\Media\MediaLibraryInterface;
use NovaNuke\Core\Media\MediaUsageChecking;
use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\Content\ContentRendererInterface;
use NovaNuke\Core\View\ViewRenderer;

final class PagesModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->get(ViewRenderer::class)->addNamespace('pages', $context->basePath . '/views');
        $context->container->get(ViewRenderer::class)->addNamespace('admin-pages', $context->basePath . '/views/admin');
        $context->container->bind(PageRepository::class, static fn (Container $c) => new PageRepository(
            $c->get(\PDO::class),
            $c->get(\NovaNuke\Core\Membership\MembershipManagerInterface::class),
        ));
        $context->container->bind(PageInput::class, static fn () => new PageInput());
    }

    public function boot(ModuleContext $context): void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::SEARCH_PROVIDERS_REGISTERING, static function (object $event) use ($context): void {
            if ($event instanceof SearchProvidersRegistering) $event->registry->add(new PagesSearchProvider($context->container->get(\PDO::class)));
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::SITEMAP_COLLECTING, static function (object $event) use ($context): void {
            if (! $event instanceof SitemapCollecting) return;
            $event->add('/pages', null, 'weekly', 0.6);
            foreach ($context->container->get(PageRepository::class)->sitemapEntries() as $page) {
                $event->add('/pages/' . $page['slug'], $page['updated_at'], 'monthly', 0.7);
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING, static function (object $event): void {
            if ($event instanceof AdminMenuBuilding) $event->add('pages::admin.title', '/admin/pages', 'pages.edit');
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::MEDIA_USAGE_CHECKING, static function (object $event) use ($context): void {
            if ($event instanceof MediaUsageChecking) $event->add('pages.image', $context->container->get(PageRepository::class)->mediaUsage($event->publicPath));
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::COMMENTS_CONTENT_CHECKING, static function (object $event) use ($context): void {
            if (! ($event instanceof CommentTargetChecking) || $event->type !== 'pages') return;
            $user = $context->container->get(\NovaNuke\Auth\AuthManager::class)->user();
            if ($context->container->get(PageRepository::class)->acceptsComments($event->contentId, $user ? (int) $user['id'] : null)) $event->accept();
        });
        $public = static fn (Container $c) => new PublicPagesController(
            $c->get(PageRepository::class), $c->get(\NovaNuke\Auth\AuthManager::class),
            $c->get(\NovaNuke\Core\Security\SessionManager::class), $c->get(ViewRenderer::class),
            $c->get(\NovaNuke\Core\Events\EventDispatcher::class),
            $c->get(ContentRendererInterface::class),
            self::commentProvider($c),
            $c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $c->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $c->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $admin = static fn (Container $c) => new AdminPagesController(
            $c->get(PageRepository::class), $c->get(PageInput::class), $c->get(\NovaNuke\Auth\AuthManager::class),
            $c->get(\NovaNuke\Core\Security\AuthorizationService::class), $c->get(\NovaNuke\Core\Logging\ActivityLogger::class),
            $c->get(\NovaNuke\Core\Events\EventDispatcher::class), $c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $c->get(\NovaNuke\Core\Security\SessionManager::class), $c->get(ViewRenderer::class),
            $c->has(MediaLibraryInterface::class) ? $c->get(MediaLibraryInterface::class) : null,
            $c->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $context->router->get('/pages', static fn (Request $r, Container $c): Response => $public($c)->index(), 'pages.index');
        $context->router->get('/pages/{slug}', static fn (Request $r, Container $c): Response => $public($c)->show($r), 'pages.show');
        $context->router->get('/admin/pages', static fn (Request $r, Container $c): Response => $admin($c)->index());
        $context->router->get('/admin/pages/new', static fn (Request $r, Container $c): Response => $admin($c)->create());
        $context->router->get('/admin/pages/{id}/edit', static fn (Request $r, Container $c): Response => $admin($c)->edit($r));
        $context->router->post('/admin/pages/save', static fn (Request $r, Container $c): Response => $admin($c)->save($r));
        $context->router->post('/admin/pages/{id}/delete', static fn (Request $r, Container $c): Response => $admin($c)->delete($r));
    }

    /**
     * Resolve the optional Comments integration defensively.
     *
     * CommentProviderInterface is the public contract. The CommentService
     * fallback keeps Pages compatible with installations where the Comments
     * module service is registered but its interface alias is unavailable.
     */
    private static function commentProvider(Container $container): ?CommentProviderInterface
    {
        if ($container->has(CommentProviderInterface::class)) {
            $provider = $container->get(CommentProviderInterface::class);
            return $provider instanceof CommentProviderInterface ? $provider : null;
        }

        $service = \Modules\Comments\src\CommentService::class;
        if (class_exists($service) && $container->has($service)) {
            $provider = $container->get($service);
            return $provider instanceof CommentProviderInterface ? $provider : null;
        }

        return null;
    }

}
