<?php

declare(strict_types=1);

namespace Modules\Welcome\src;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\View\ViewRenderer;

final class WelcomeModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $views = $context->container->get(ViewRenderer::class);
        $views->addNamespace('welcome', $context->basePath . '/views');
        $views->addNamespace('admin-welcome', $context->basePath . '/views/admin');
        $context->container->bind(WelcomeContentResolver::class, static function (Container $container) use ($context): WelcomeContentResolver {
            return new WelcomeContentResolver(
                $container->get(\NovaNuke\Core\Settings\SettingsRepository::class),
                $container->get(\NovaNuke\Core\I18n\Translator::class),
                $context->basePath . '/language',
            );
        });
    }

    public function boot(ModuleContext $context): void
    {
        $events = $context->events;
        $events->listen(\NovaNuke\Core\Events\EventName::ADMIN_MENU_BUILDING, static function (object $event): void {
            if ($event instanceof AdminMenuBuilding) $event->add('welcome::admin.navigation', '/admin/welcome', 'welcome.manage', 'hand-waving', 'system');
        });
        $admin = static fn (Container $container): AdminWelcomeController => new AdminWelcomeController(
            $container->get(\NovaNuke\Auth\AuthManager::class),
            $container->get(\NovaNuke\Core\Security\AuthorizationService::class),
            $container->get(\NovaNuke\Core\Settings\SettingsRepository::class),
            $container->get(WelcomeContentResolver::class),
            $container->get(\NovaNuke\Core\Logging\ActivityLogger::class),
            $container->get(\NovaNuke\Core\Security\CsrfTokenManager::class),
            $container->get(\NovaNuke\Core\Security\SessionManager::class),
            $container->get(ViewRenderer::class),
            $container->get(\NovaNuke\Core\I18n\Translator::class),
        );
        $context->router->get('/admin/welcome', static fn (Request $request, Container $container): Response => $admin($container)->show());
        $context->router->post('/admin/welcome/save', static fn (Request $request, Container $container): Response => $admin($container)->save($request));
        $context->router->get('/welcome', static function (Request $request, Container $container) use ($events): Response {
            $database = $container->get(\PDO::class);
            $message = $database->query('SELECT message FROM welcome_messages ORDER BY id LIMIT 1')->fetchColumn();
            $event = new WelcomePageEvent(is_string($message) ? $message : 'Welcome to NovaNuke.');
            $events->dispatch('welcome.page.rendering', $event);
            $inventory = $container->get(ModuleManager::class)->inventory();
            $available = static fn (string $slug): bool => ($inventory[$slug]['enabled'] ?? false) === true;
            $links = [];
            foreach ([
                'news' => ['/news', 'news'],
                'downloads' => ['/downloads', 'downloads'],
                'wiki' => ['/wiki', 'docs'],
                'web-links' => ['/links', 'resources'],
            ] as $slug => [$url, $key]) {
                if ($available($slug)) $links[] = ['url' => $url, 'key' => $key];
            }

            $content = $container->get(WelcomeContentResolver::class)->resolve();
            foreach ($links as &$link) {
                $link['label'] = $content['links'][$link['key']]['label'];
                $link['help'] = $content['links'][$link['key']]['help'];
                $link['url'] = $content['links'][$link['key']]['url'];
            }
            unset($link);

            return Response::html($container->get(ViewRenderer::class)->render('@welcome/index.twig', [
                'message' => $event->message,
                'links' => $links,
                'content' => $content,
            ]));
        }, 'welcome.index');
    }
}
