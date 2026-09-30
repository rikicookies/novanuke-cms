<?php

declare(strict_types=1);

namespace Modules\Comments\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Content\ContentRendererInterface;

final class CommentsModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container->get(ViewRenderer::class)->addNamespace('comments', $context->basePath . '/views');
        $context->container->get(ViewRenderer::class)->addNamespace('admin-comments', $context->basePath . '/views/admin');
        $context->container->bind(CommentRepository::class, static fn (Container $c) => new CommentRepository($c->get(\PDO::class)));
        $context->container->bind(CommentTargetAccessGuard::class, static fn (Container $c) => new CommentTargetAccessGuard(
            $c->get(\NovaNuke\Core\Events\EventDispatcher::class),
        ));
        $context->container->bind(CommentService::class, static fn (Container $c) => new CommentService(
            $c->get(CommentRepository::class), new CommentTreeBuilder(), $c->get(\NovaNuke\Auth\AuthManager::class),
            $c->get(\NovaNuke\Core\Settings\SettingsRepository::class), $c->get(\NovaNuke\Core\Events\EventDispatcher::class),
            $c->get(CommentTargetAccessGuard::class),
            new DatabaseRateLimiter($c->get(\PDO::class), 5, 600, 'comments'),
            (string) $c->get(ConfigRepository::class)->get('app.key', ''),
            $c->get(ContentRendererInterface::class),
        ));
        $context->container->bind(CommentProviderInterface::class, static fn (Container $c) => $c->get(CommentService::class));
    }

    public function boot(ModuleContext $context): void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::PROFILE_STATISTICS_BUILDING,static function(object$event)use($context):void{if($event instanceof \NovaNuke\Core\Profile\ProfileStatisticsBuilding)$event->add('Approved comments',$context->container->get(CommentRepository::class)->approvedCountByUser($event->profileId));});
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING, static function (object $event): void {
            if ($event instanceof AdminMenuBuilding) $event->add('comments::title', '/admin/comments', 'comments.moderate');
        });
        $public = static fn (Container $c) => new PublicCommentsController(
            $c->get(CommentService::class), $c->get(\NovaNuke\Auth\AuthManager::class),
            $c->get(\NovaNuke\Core\Logging\ActivityLogger::class), $c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $c->get(\NovaNuke\Core\Security\SessionManager::class),
            $c->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $admin = static fn (Container $c) => new AdminCommentsController(
            $c->get(CommentRepository::class), $c->get(\NovaNuke\Core\Settings\SettingsRepository::class),
            $c->get(\NovaNuke\Auth\AuthManager::class), $c->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $c->get(\NovaNuke\Core\Logging\ActivityLogger::class), $c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $c->get(ViewRenderer::class), $c->get(\NovaNuke\Core\Security\SessionManager::class),
            $c->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $context->router->post('/comments/{id}/report', static fn (Request $r, Container $c): Response => $public($c)->report($r));
        $context->router->post('/comments/{id}/edit', static fn (Request $r, Container $c): Response => $public($c)->edit($r));
        $context->router->post('/comments/{id}/react', static fn (Request $r, Container $c): Response => $public($c)->react($r));
        $context->router->post('/comments/{type}/{id}', static fn (Request $r, Container $c): Response => $public($c)->create($r));
        $context->router->get('/admin/comments', static fn (Request $r, Container $c): Response => $admin($c)->index());
        $context->router->post('/admin/comments/{id}/moderate', static fn (Request $r, Container $c): Response => $admin($c)->moderate($r));
        $context->router->post('/admin/comment-reports/{id}/resolve', static fn (Request $r, Container $c): Response => $admin($c)->resolve($r));
        $context->router->post('/admin/comments/settings', static fn (Request $r, Container $c): Response => $admin($c)->settings($r));
    }
}
