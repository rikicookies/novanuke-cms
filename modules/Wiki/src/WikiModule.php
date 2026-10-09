<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Search\SearchProvidersRegistering;
use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Core\Comments\CommentTargetChecking;
use NovaNuke\Core\Sitemap\SitemapCollecting;

final class WikiModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->get(ViewRenderer::class)->addNamespace('wiki', $context->basePath . '/views');
        $context->container->bind(WikiInput::class, static fn (): WikiInput => new WikiInput());
        $context->container->bind(WikiNavigation::class, static fn (): WikiNavigation => new WikiNavigation());
        $context->container->bind(WikiMarkdownRenderer::class, static fn (): WikiMarkdownRenderer => new WikiMarkdownRenderer());
        $context->container->bind(WikiLinkPresenter::class, static fn (Container $container): WikiLinkPresenter => new WikiLinkPresenter(
            $container->get(WikiInput::class),
        ));
        $context->container->bind(WikiAttachmentUpload::class, static fn (): WikiAttachmentUpload => new WikiAttachmentUpload());
        $context->container->bind(WikiAttachmentManager::class, static fn (Container $container): WikiAttachmentManager => new WikiAttachmentManager(
            $container->get(\PDO::class),
            $container->get(WikiAttachmentUpload::class),
            NOVANUKE_ROOT . '/storage/private/wiki',
        ));
        $context->container->bind(WikiLinkIndexer::class, static fn (Container $container): WikiLinkIndexer => new WikiLinkIndexer(
            $container->get(WikiInput::class),
        ));
        $context->container->bind(WikiRevisionComparator::class, static fn (): WikiRevisionComparator => new WikiRevisionComparator());
        $context->container->bind(WikiMarkdownFile::class, static fn (): WikiMarkdownFile => new WikiMarkdownFile());
        $context->container->bind(WikiArchive::class, static fn (): WikiArchive => new WikiArchive());
        $context->container->bind(WikiFolderImport::class, static fn (Container $container): WikiFolderImport => new WikiFolderImport(
            $container->get(WikiMarkdownFile::class),
            $container->get(WikiInput::class),
        ));
        $context->container->bind(WikiRepository::class, static fn (Container $container): WikiRepository => new WikiRepository(
            $container->get(\PDO::class),
            $container->get(\NovaNuke\Core\Membership\MembershipManagerInterface::class),
            $container->get(WikiLinkIndexer::class),
        ));
    }

    public function boot(ModuleContext $context): void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::SITEMAP_COLLECTING, static function (object $event) use ($context): void {
            if (! $event instanceof SitemapCollecting) return;
            $event->add('/wiki', null, 'weekly', 0.6);
            foreach ($context->container->get(WikiRepository::class)->sitemapEntries() as $page) {
                $path = ($page['namespace'] === '' ? '' : $page['namespace'] . ':') . $page['slug'];
                $event->add('/wiki/' . $path, $page['updated_at'], 'weekly', 0.7);
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::COMMENTS_CONTENT_CHECKING, static function (object $event) use ($context): void {
            if (! ($event instanceof CommentTargetChecking) || $event->type !== 'wiki') return;
            $user = $context->container->get(\NovaNuke\Auth\AuthManager::class)->user();
            if ($context->container->get(WikiRepository::class)->acceptsComments(
                $event->contentId,
                $user ? (int) $user['id'] : null,
            )) $event->accept();
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::SEARCH_PROVIDERS_REGISTERING, static function (object $event) use ($context): void {
            if ($event instanceof SearchProvidersRegistering) {
                $event->registry->add(new WikiSearchProvider(
                    $context->container->get(\PDO::class),
                    $context->container->get(\NovaNuke\Core\Access\AccessAudience::class),
                ));
            }
        });
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING, static function (object $event): void {
            if ($event instanceof AdminMenuBuilding) $event->add('Wiki', '/admin/wiki', 'wiki.edit', 'wiki', 'content');
        });
        $public = static fn (Container $container): PublicWikiController => new PublicWikiController(
            $container->get(WikiRepository::class),
            $container->get(WikiInput::class),
            $container->get(WikiNavigation::class),
            $container->get(WikiLinkPresenter::class),
            $container->get(WikiAttachmentManager::class),
            $container->get(\NovaNuke\Auth\AuthManager::class),
            $container->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $container->get(WikiMarkdownRenderer::class),
            $container->get(ViewRenderer::class),
            $container->get(\NovaNuke\Core\Security\SessionManager::class),
            $container->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $container->has(CommentProviderInterface::class) ? $container->get(CommentProviderInterface::class) : null,
            $container->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $admin = static fn (Container $container): AdminWikiController => new AdminWikiController(
            $container->get(WikiRepository::class),
            $container->get(WikiInput::class),
            $container->get(\NovaNuke\Auth\AuthManager::class),
            $container->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $container->get(\NovaNuke\Core\Logging\ActivityLogger::class),
            $container->get(\NovaNuke\Core\Events\EventDispatcher::class),
            $container->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $container->get(\NovaNuke\Core\Security\SessionManager::class),
            $container->get(ViewRenderer::class),
            $container->get(WikiMarkdownRenderer::class),
            $container->get(WikiRevisionComparator::class),
            $container->get(WikiMarkdownFile::class),
            $container->get(WikiAttachmentManager::class),
            $container->get(WikiArchive::class),
            $container->get(WikiFolderImport::class),
            $container->get(\NovaNuke\Core\I18n\Translator::class),
        );

        $asset = static function (string $file, string $contentType = 'application/javascript; charset=UTF-8') use ($context): Response {
            $path = $context->basePath . '/assets/' . $file;
            if (! is_file($path) || ! is_readable($path)) return Response::html('Not Found', 404);
            return (new Response((string) file_get_contents($path), 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]));
        };

        $context->router->get('/wiki-assets/wiki.css', static fn (Request $request, Container $container): Response => $asset('wiki.css', 'text/css; charset=UTF-8'), 'wiki.asset.styles');
        $context->router->get('/wiki-assets/editor.js', static fn (Request $request, Container $container): Response => $asset('wiki-editor.js'), 'wiki.asset.editor');
        $context->router->get('/wiki-assets/folder-import.js', static fn (Request $request, Container $container): Response => $asset('wiki-folder-import.js'), 'wiki.asset.folder-import');
        $context->router->get('/wiki-assets/bulk-actions.js', static fn (Request $request, Container $container): Response => $asset('wiki-bulk-actions.js'), 'wiki.asset.bulk-actions');
        $context->router->get('/wiki', static fn (Request $request, Container $container): Response => $public($container)->index($request), 'wiki.index');
        $context->router->get('/wiki/map', static fn (Request $request, Container $container): Response => $public($container)->map(), 'wiki.map');
        $context->router->get('/wiki/recent', static fn (Request $request, Container $container): Response => $public($container)->recent(), 'wiki.recent');
        $context->router->get('/wiki/search', static fn (Request $request, Container $container): Response => $public($container)->search($request), 'wiki.search');
        $context->router->get('/wiki/attachments/{attachment}', static fn (Request $request, Container $container): Response => $public($container)->attachment($request), 'wiki.attachment');
        $context->router->get('/wiki/{path}', static fn (Request $request, Container $container): Response => $public($container)->show($request), 'wiki.show');
        $context->router->get('/admin/wiki', static fn (Request $request, Container $container): Response => $admin($container)->index($request));
        $context->router->get('/admin/wiki/namespaces', static fn (Request $request, Container $container): Response => $admin($container)->namespaces($request));
        $context->router->post('/admin/wiki/namespaces/move', static fn (Request $request, Container $container): Response => $admin($container)->moveNamespace($request));
        $context->router->get('/admin/wiki/missing-links', static fn (Request $request, Container $container): Response => $admin($container)->missingLinks());
        $context->router->get('/admin/wiki/new', static fn (Request $request, Container $container): Response => $admin($container)->create($request));
        $context->router->get('/admin/wiki/export-all', static fn (Request $request, Container $container): Response => $admin($container)->exportAll());
        $context->router->get('/admin/wiki/{id}/edit', static fn (Request $request, Container $container): Response => $admin($container)->edit($request));
        $context->router->get('/admin/wiki/{id}/history', static fn (Request $request, Container $container): Response => $admin($container)->history($request));
        $context->router->get('/admin/wiki/{id}/compare', static fn (Request $request, Container $container): Response => $admin($container)->compare($request));
        $context->router->get('/admin/wiki/{id}/export', static fn (Request $request, Container $container): Response => $admin($container)->export($request));
        $context->router->get('/admin/wiki/{id}/revisions/{revision}', static fn (Request $request, Container $container): Response => $admin($container)->revision($request));
        $context->router->post('/admin/wiki/import', static fn (Request $request, Container $container): Response => $admin($container)->import($request));
        $context->router->post('/admin/wiki/import-folder', static fn (Request $request, Container $container): Response => $admin($container)->importFolder($request));
        $context->router->post('/admin/wiki/preview', static fn (Request $request, Container $container): Response => $admin($container)->preview($request));
        $context->router->post('/admin/wiki/bulk', static fn (Request $request, Container $container): Response => $admin($container)->bulk($request));
        $context->router->post('/admin/wiki/save', static fn (Request $request, Container $container): Response => $admin($container)->save($request));
        $context->router->post('/admin/wiki/{id}/revisions/{revision}/restore', static fn (Request $request, Container $container): Response => $admin($container)->restore($request));
        $context->router->post('/admin/wiki/{id}/delete', static fn (Request $request, Container $container): Response => $admin($container)->delete($request));
        $context->router->post('/admin/wiki/{id}/attachments', static fn (Request $request, Container $container): Response => $admin($container)->uploadAttachment($request));
        $context->router->post('/admin/wiki/{id}/attachments/{attachment}/delete', static fn (Request $request, Container $container): Response => $admin($container)->deleteAttachment($request));
    }
}
