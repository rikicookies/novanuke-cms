<?php

declare(strict_types=1);

use NovaNuke\Auth\AuthController;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Auth\LoginValidator;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Security\RateLimiter;
use NovaNuke\Core\Security\AuthorizationService;

$authController = static fn (Container $container): AuthController => new AuthController(
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(RateLimiter::class),
    new LoginValidator(),
    $container->get(CsrfTokenManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);

$router->get('/login', static fn (Request $request, Container $container): Response =>
    $authController($container)->showLogin($request)
);
$router->post('/login', static fn (Request $request, Container $container): Response =>
    $authController($container)->login($request)
);
$router->post('/logout', static fn (Request $request, Container $container): Response =>
    $authController($container)->logout($request)
);
