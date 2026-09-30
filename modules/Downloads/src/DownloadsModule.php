<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use NovaNuke\Core\Content\ContentRendererInterface;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Search\SearchProvidersRegistering;

final class DownloadsModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->get(ViewRenderer::class)->addNamespace('downloads', $context->basePath . '/views');
        $context->container->get(ViewRenderer::class)->addNamespace('admin-downloads', $context->basePath . '/views/admin');
        $context->container->bind(DownloadRepository::class, static fn (Container $c) => new DownloadRepository(
            $c->get(\PDO::class),
            $c->get(\NovaNuke\Core\Membership\MembershipManagerInterface::class),
            $c->get(\NovaNuke\Core\Settings\SettingsRepository::class)->integer('site.per_page', 10, 5, 100),
        ));
        $context->container->bind(DownloadInput::class, static fn () => new DownloadInput());
        $context->container->bind(DownloadStorage::class, static fn () => new DownloadStorage(NOVANUKE_ROOT . '/storage/private/downloads'));
        $context->container->bind(DownloadOrphanCleaner::class, static fn (Container $c) => new DownloadOrphanCleaner(
            NOVANUKE_ROOT . '/storage/private/downloads',
            static fn (): array => $c->get(DownloadRepository::class)->storedNames(),
        ));
        $context->container->bind(DownloadManager::class, static fn (Container $c) => new DownloadManager(
            $c->get(DownloadRepository::class), new DownloadUploadValidator(), $c->get(DownloadStorage::class),
            $c->get(\NovaNuke\Auth\AuthManager::class), $c->get(\NovaNuke\Core\Events\EventDispatcher::class),
            new DatabaseRateLimiter($c->get(\PDO::class), 5, 600, 'download-reports'), (string) $c->get(ConfigRepository::class)->get('app.key', ''),
        ));
    }

    public function boot(ModuleContext $context): void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::SEARCH_PROVIDERS_REGISTERING, static function (object $event) use ($context): void {
            if ($event instanceof SearchProvidersRegistering) $event->registry->add(new DownloadsSearchProvider($context->container->get(\PDO::class)));
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING, static function (object $event): void { if ($event instanceof AdminMenuBuilding) $event->add('Downloads', '/admin/downloads', 'downloads.manage'); });
        $public = static fn (Container $c) => new PublicDownloadsController(
            $c->get(DownloadRepository::class), $c->get(DownloadManager::class), $c->get(\NovaNuke\Auth\AuthManager::class),
            $c->get(\NovaNuke\Core\Events\EventDispatcher::class), $c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $c->get(\NovaNuke\Core\Security\SessionManager::class), $c->get(ViewRenderer::class),
            $c->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $c->get(ContentRendererInterface::class),
            $c->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $admin = static fn (Container $c) => new AdminDownloadsController(
            $c->get(DownloadRepository::class), $c->get(DownloadManager::class), $c->get(DownloadInput::class),
            $c->get(\NovaNuke\Auth\AuthManager::class), $c->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $c->get(\NovaNuke\Core\Logging\ActivityLogger::class), $c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $c->get(\NovaNuke\Core\Security\SessionManager::class), $c->get(ViewRenderer::class),
            $c->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $context->router->get('/downloads', static fn (Request $r, Container $c): Response => $public($c)->index($r), 'downloads.index');
        $context->router->get('/downloads/category/{slug}', static fn (Request $r, Container $c): Response => $public($c)->index($r, (string) $r->attribute('slug')), 'downloads.category');
        $context->router->get('/downloads/{slug}/get', static fn (Request $r, Container $c): Response => $public($c)->deliver($r), 'downloads.deliver');
        $context->router->post('/downloads/{id}/report', static fn (Request $r, Container $c): Response => $public($c)->report($r));
        $context->router->get('/downloads/{slug}', static fn (Request $r, Container $c): Response => $public($c)->show($r), 'downloads.show');
        $context->router->get('/admin/downloads', static fn (Request $r, Container $c): Response => $admin($c)->index());
        $context->router->get('/admin/downloads/new', static fn (Request $r, Container $c): Response => $admin($c)->create());
        $context->router->get('/admin/downloads/{id}/edit', static fn (Request $r, Container $c): Response => $admin($c)->edit($r));
        $context->router->post('/admin/downloads/save', static fn (Request $r, Container $c): Response => $admin($c)->save($r));
        $context->router->post('/admin/downloads/category', static fn (Request $r, Container $c): Response => $admin($c)->category($r));
        $context->router->post('/admin/downloads/{id}/delete', static fn (Request $r, Container $c): Response => $admin($c)->delete($r));
        $context->router->post('/admin/download-reports/{id}/resolve', static fn (Request $r, Container $c): Response => $admin($c)->resolve($r));
    }
}
