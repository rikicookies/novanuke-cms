<?php

declare(strict_types=1);

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Version;
use NovaNuke\Core\Modules\ModuleHomepageRegistry;
use NovaNuke\Core\Home\HomeContentResolver;
use NovaNuke\Core\Settings\SettingsRepository;

$router->get('/', static function (Request $request, Container $container): Response {
    $homepage = $container->get(SettingsRepository::class)->string('site.homepage', 'home');
    $target = $container->get(ModuleHomepageRegistry::class)->url($homepage);
    if ($target !== null) return Response::redirect($target);

    $user = $container->get(AuthManager::class)->user();
    $html = $container->get(ViewRenderer::class)->render('home.twig', [
        'version' => Version::CURRENT,
        'user' => $user,
        'csrf_token' => $container->get(CsrfTokenManager::class)->token(),
        'home_content' => $container->get(HomeContentResolver::class)->resolve(is_array($user) ? (string) ($user['username'] ?? '') : null),
    ]);

    return Response::html($html);
}, 'home');

$router->get('/health', static fn (): Response => Response::json([
    'status' => 'ok',
    'application' => 'NovaNuke',
]));

$router->get('/hello/{name}', static fn (Request $request): Response => Response::html(
    '<h1>Hello, ' . htmlspecialchars((string) $request->attribute('name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>',
));
