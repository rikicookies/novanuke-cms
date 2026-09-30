<?php

declare(strict_types=1);

namespace Modules\PrivateMessages\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Messaging\PrivateMessageComposerInterface;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\Modules\ModuleContext;use NovaNuke\Core\Modules\ModuleInterface;use NovaNuke\Core\Security\DatabaseRateLimiter;use NovaNuke\Core\View\ViewRenderer;

final class PrivateMessagesModule implements ModuleInterface
{
    public function register(ModuleContext $context):void
    {
        $views=$context->container->get(ViewRenderer::class);$views->addNamespace('private-messages',$context->basePath.'/views');$views->addGlobal('private_messages_available',true);
        $context->container->bind(PrivateMessageRepository::class,static fn(Container $c)=>new PrivateMessageRepository($c->get(\PDO::class)));
        $context->container->bind(PrivateMessageService::class,static fn(Container $c)=>new PrivateMessageService($c->get(PrivateMessageRepository::class),new PrivateMessageInput(),new DatabaseRateLimiter($c->get(\PDO::class),20,3600,'private-messages-send'),new DatabaseRateLimiter($c->get(\PDO::class),5,3600,'private-messages-report'),$c->get(\NovaNuke\Core\Events\EventDispatcher::class),$c->get(\NovaNuke\Core\Content\ContentRendererInterface::class)));
        $context->container->bind(PrivateMessageComposerInterface::class, static fn (Container $c) => $c->get(PrivateMessageService::class));
    }
    public function boot(ModuleContext $context):void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING,static function(object $e):void{if($e instanceof AdminMenuBuilding)$e->add('private-messages::title','/admin/private-messages','private-messages.moderate','message','community');});
        $public=static fn(Container $c)=>new PublicPrivateMessagesController($c->get(PrivateMessageRepository::class),$c->get(PrivateMessageService::class),$c->get(\NovaNuke\Auth\AuthManager::class),$c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),$c->get(\NovaNuke\Core\Security\SessionManager::class),$c->get(ViewRenderer::class),$c->get(\NovaNuke\Core\I18n\Translator::class));
        $admin=static fn(Container $c)=>new AdminPrivateMessagesController($c->get(PrivateMessageRepository::class),$c->get(\NovaNuke\Auth\AuthManager::class),$c->get(\NovaNuke\Core\Security\AuthorizationService::class),$c->get(\NovaNuke\Core\Logging\ActivityLogger::class),$c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),$c->get(\NovaNuke\Core\Security\SessionManager::class),$c->get(ViewRenderer::class),$c->get(\NovaNuke\Core\I18n\Translator::class));
        $context->router->get('/messages',static fn(Request $r,Container $c):Response=>$public($c)->inbox(),'private-messages.inbox');
        $context->router->get('/messages/sent',static fn(Request $r,Container $c):Response=>$public($c)->sent());
        $context->router->get('/messages/compose',static fn(Request $r,Container $c):Response=>$public($c)->compose($r));
        $context->router->post('/messages',static fn(Request $r,Container $c):Response=>$public($c)->store($r));
        $context->router->get('/messages/blocks',static fn(Request $r,Container $c):Response=>$public($c)->blocks());
        $context->router->post('/messages/users/{id}/block',static fn(Request $r,Container $c):Response=>$public($c)->block($r));
        $context->router->post('/messages/users/{id}/unblock',static fn(Request $r,Container $c):Response=>$public($c)->unblock($r));
        $context->router->get('/messages/{id}',static fn(Request $r,Container $c):Response=>$public($c)->show($r));
        $context->router->post('/messages/{id}/reply',static fn(Request $r,Container $c):Response=>$public($c)->reply($r));
        $context->router->post('/messages/{id}/delete',static fn(Request $r,Container $c):Response=>$public($c)->delete($r));
        $context->router->post('/messages/{id}/report',static fn(Request $r,Container $c):Response=>$public($c)->report($r));
        $context->router->get('/admin/private-messages',static fn(Request $r,Container $c):Response=>$admin($c)->index());
        $context->router->post('/admin/private-message-reports/{id}/resolve',static fn(Request $r,Container $c):Response=>$admin($c)->resolve($r));
    }
}
