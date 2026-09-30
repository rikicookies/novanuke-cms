<?php

declare(strict_types=1);

namespace Modules\Polls\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;use NovaNuke\Core\Blocks\BlockRendering;use NovaNuke\Core\Container\Container;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\Modules\ModuleContext;use NovaNuke\Core\Modules\ModuleInterface;use NovaNuke\Core\View\ViewRenderer;

final class PollsModule implements ModuleInterface
{
    public function register(ModuleContext $context):void{$context->container->get(ViewRenderer::class)->addNamespace('polls',$context->basePath.'/views');$context->container->bind(PollRepository::class,static fn(Container $c)=>new PollRepository($c->get(\PDO::class)));$context->container->bind(PollService::class,static fn(Container $c)=>new PollService($c->get(PollRepository::class),$c->get(\NovaNuke\Auth\AuthManager::class),$c->get(\NovaNuke\Core\Security\SessionManager::class),$c->get(\NovaNuke\Core\Config\ConfigRepository::class)));}
    public function boot(ModuleContext $context):void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING,static function(object $e):void{if($e instanceof AdminMenuBuilding)$e->add('polls::title','/admin/polls','polls.manage','poll','resources');});
        $context->events->listen(\NovaNuke\Core\Events\EventName::BLOCK_RENDERING,static function(object $e)use($context):void{if(!$e instanceof BlockRendering||$e->block['type']!=='polls-active')return;$polls=$context->container->get(PollRepository::class);$poll=$polls->active();if($poll===null)return;$request=Request::capture();$service=$context->container->get(PollService::class);$e->render($context->container->get(ViewRenderer::class)->render('@polls/block.twig',['poll'=>$poll,'has_voted'=>$polls->hasVoted((int)$poll['id'],$service->key($request)),'csrf_token'=>$context->container->get(\NovaNuke\Core\Security\CsrfTokenManager::class)->token(),'return_path'=>$request->path()]));});
        $public=static fn(Container $c)=>new PublicPollsController($c->get(PollRepository::class),$c->get(PollService::class),$c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),$c->get(\NovaNuke\Core\Security\SessionManager::class),$c->get(ViewRenderer::class),$c->get(\NovaNuke\Core\I18n\Translator::class));
        $admin=static fn(Container $c)=>new AdminPollsController($c->get(PollRepository::class),new PollInput(),$c->get(\NovaNuke\Auth\AuthManager::class),$c->get(\NovaNuke\Core\Security\AuthorizationService::class),$c->get(\NovaNuke\Core\Logging\ActivityLogger::class),$c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),$c->get(\NovaNuke\Core\Security\SessionManager::class),$c->get(ViewRenderer::class),$c->get(\NovaNuke\Core\I18n\Translator::class));
        $context->router->get('/polls',static fn(Request $r,Container $c):Response=>$public($c)->index(),'polls.index');$context->router->get('/polls/{id}',static fn(Request $r,Container $c):Response=>$public($c)->show($r),'polls.show');$context->router->post('/polls/{id}/vote',static fn(Request $r,Container $c):Response=>$public($c)->vote($r));
        $context->router->get('/admin/polls',static fn(Request $r,Container $c):Response=>$admin($c)->index($r));$context->router->post('/admin/polls/save',static fn(Request $r,Container $c):Response=>$admin($c)->save($r));$context->router->post('/admin/polls/{id}/delete',static fn(Request $r,Container $c):Response=>$admin($c)->delete($r));
    }
}
