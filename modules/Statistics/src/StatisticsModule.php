<?php

declare(strict_types=1);

namespace Modules\Statistics\src;

use NovaNuke\Core\Admin\AdminMenuBuilding;use NovaNuke\Core\Blocks\BlockRendering;use NovaNuke\Core\Container\Container;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\Maintenance\MaintenancePruning;use NovaNuke\Core\Modules\ModuleContext;use NovaNuke\Core\Modules\ModuleInterface;use NovaNuke\Core\Settings\SettingsRepository;use NovaNuke\Core\View\ViewRenderer;

final class StatisticsModule implements ModuleInterface
{
    public function register(ModuleContext $context):void{$context->container->get(ViewRenderer::class)->addNamespace('statistics',$context->basePath.'/views');$context->container->bind(StatisticsRepository::class,static fn(Container $c)=>new StatisticsRepository($c->get(\PDO::class)));}
    public function boot(ModuleContext $context):void
    {
        $context->events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING,static function(object $e):void{if($e instanceof AdminMenuBuilding)$e->add('statistics::navigation.label','/admin/statistics','statistics.view-admin','chart','overview');});$context->events->listen(\NovaNuke\Core\Events\EventName::BLOCK_RENDERING,static function(object $e)use($context):void{if(!$e instanceof BlockRendering||$e->block['type']!=='statistics-summary')return;$e->render($context->container->get(ViewRenderer::class)->render('@statistics/block.twig',['summary'=>$context->container->get(StatisticsRepository::class)->summary()]));});$context->events->listen(\NovaNuke\Core\Events\EventName::MAINTENANCE_PRUNING,static function(object $e)use($context):void{if($e instanceof MaintenancePruning)$e->add('statistics.daily',$context->container->get(StatisticsRepository::class)->prune($e->dryRun));});
        $public=static fn(Container $c)=>new PublicStatisticsController($c->get(StatisticsRepository::class),$c->get(SettingsRepository::class),$c->get(ViewRenderer::class));$admin=static fn(Container $c)=>new AdminStatisticsController($c->get(StatisticsRepository::class),$c->get(SettingsRepository::class),$c->get(\NovaNuke\Auth\AuthManager::class),$c->get(\NovaNuke\Core\Security\AuthorizationService::class),$c->get(\NovaNuke\Core\Logging\ActivityLogger::class),$c->get(\NovaNuke\Core\Security\CsrfTokenManager::class),$c->get(\NovaNuke\Core\Security\SessionManager::class),$c->get(ViewRenderer::class),$c->get(\NovaNuke\Core\I18n\Translator::class));
        $context->router->get('/statistics',static fn(Request $r,Container $c):Response=>$public($c)->index(),'statistics.index');$context->router->get('/admin/statistics',static fn(Request $r,Container $c):Response=>$admin($c)->index());$context->router->post('/admin/statistics/settings',static fn(Request $r,Container $c):Response=>$admin($c)->settings($r));
        if($context->container->get(SettingsRepository::class)->boolean('statistics.collection_enabled',true)){$siteUrl=$context->container->get(SettingsRepository::class)->string('site.url','');$host=(string)parse_url($siteUrl,PHP_URL_HOST);(new StatisticsTracker($context->container->get(StatisticsRepository::class),new StatisticsDimensions(),$host))->track(Request::capture());}
    }
}
