<?php

declare(strict_types=1);

namespace Modules\Quotes\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Events\EventName;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\View\ViewRenderer;

final class QuotesModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->get(ViewRenderer::class)->addNamespace('quotes', $context->basePath . '/views');
        $context->container->bind(QuoteRepository::class, static fn (Container $container): QuoteRepository => new QuoteRepository($container->get(\PDO::class)));
    }

    public function boot(ModuleContext $context): void
    {
        $context->events->listen(EventName::ADMIN_MENU_BUILDING, static function (object $event): void {
            if ($event instanceof AdminMenuBuilding) $event->add('quotes::admin.title', '/admin/quotes', 'quotes.manage', 'quote');
        });

        $controller = static fn (Container $container): QuotesController => new QuotesController(
            $container->get(QuoteRepository::class),
            $container->get(\NovaNuke\Auth\AuthManager::class),
            $container->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $container->get(\NovaNuke\Core\Logging\ActivityLogger::class),
            $container->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $container->get(\NovaNuke\Core\Security\SessionManager::class),
            $container->get(ViewRenderer::class),
            $container->get(\NovaNuke\Core\I18n\Translator::class),
        );

        $context->router->get('/quotes', static fn (Request $request, Container $container): Response => $controller($container)->index(), 'quotes.index');
        $context->router->get('/admin/quotes', static fn (Request $request, Container $container): Response => $controller($container)->admin(), 'quotes.admin');
        $context->router->post('/admin/quotes', static fn (Request $request, Container $container): Response => $controller($container)->create($request), 'quotes.create');
        $context->router->post('/admin/quotes/{id}/delete', static fn (Request $request, Container $container): Response => $controller($container)->delete($request), 'quotes.delete');
    }
}
