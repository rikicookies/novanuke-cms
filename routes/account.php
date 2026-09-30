<?php

declare(strict_types=1);

use NovaNuke\Auth\AccountController;
use NovaNuke\Auth\AccountDeletionInput;
use NovaNuke\Auth\AccountEmailController;
use NovaNuke\Auth\AccountEmailInput;
use NovaNuke\Auth\AccountEmailService;
use NovaNuke\Auth\AccountLifecycleService;
use NovaNuke\Auth\AccountLocaleController;
use NovaNuke\Auth\AccountPasswordService;
use NovaNuke\Auth\AccountSecurityController;
use NovaNuke\Auth\AccountSecurityRepository;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Auth\AvatarStorage;
use NovaNuke\Auth\AvatarUploadValidator;
use NovaNuke\Auth\ProfileInput;
use NovaNuke\Auth\ProfileRepository;
use NovaNuke\Auth\PublicProfileController;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\I18n\LocaleRegistry;
use NovaNuke\Core\Content\ContentRendererInterface;

$publicProfileController = static fn (Container $container): PublicProfileController => new PublicProfileController(
    $container->get(ProfileRepository::class), $container->get(AvatarStorage::class),
    $container->get(AuthManager::class), $container->get(ViewRenderer::class),
    $container->get(ContentRendererInterface::class),
    $container->get(\NovaNuke\Core\Events\EventDispatcher::class),
    $container->get(CsrfTokenManager::class),
    $container->get(\NovaNuke\Core\Membership\MembershipManagerInterface::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/users', static fn (Request $request, Container $container): Response =>
    $publicProfileController($container)->index($request)
);
$router->get('/users/{username}', static fn (Request $request, Container $container): Response =>
    $publicProfileController($container)->show($request)
);
$router->get('/avatars/{filename}', static fn (Request $request, Container $container): Response =>
    $publicProfileController($container)->avatar($request)
);

$accountController = static fn (Container $container): AccountController => new AccountController(
    $container->get(AuthManager::class), $container->get(ProfileRepository::class), new ProfileInput($container->get(LocaleRegistry::class)),
    new AvatarUploadValidator(), $container->get(AvatarStorage::class), $container->get(AccountPasswordService::class),
    new DatabaseRateLimiter($container->get(\PDO::class), 5, 900, 'account-password'),
    $container->get(ActivityLogger::class), $container->get(CsrfTokenManager::class),
    $container->get(SessionManager::class), $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\Membership\MembershipManagerInterface::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/account/profile', static fn (Request $request, Container $container): Response => $accountController($container)->edit());
$router->post('/account/profile', static fn (Request $request, Container $container): Response => $accountController($container)->update($request));
$router->post('/account/locale', static fn (Request $request, Container $container): Response => (new AccountLocaleController(
    $container->get(AuthManager::class), $container->get(ProfileRepository::class),
    $container->get(LocaleRegistry::class), $container->get(CsrfTokenManager::class),
))->update($request));
$router->post('/account/avatar', static fn (Request $request, Container $container): Response => $accountController($container)->avatar($request));
$router->post('/account/avatar/remove', static fn (Request $request, Container $container): Response => $accountController($container)->removeAvatar($request));
$router->post('/account/password', static fn (Request $request, Container $container): Response => $accountController($container)->password($request));

$securityController = static fn (Container $container): AccountSecurityController => new AccountSecurityController(
    $container->get(AuthManager::class), $container->get(AccountSecurityRepository::class),
    $container->get(AccountLifecycleService::class), new AccountDeletionInput(), $container->get(AvatarStorage::class),
    $container->get(ProfileRepository::class),
    new DatabaseRateLimiter($container->get(\PDO::class), 3, 3600, 'account-delete'),
    $container->get(CsrfTokenManager::class), $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/account/security', static fn (Request $request, Container $container): Response => $securityController($container)->show());
$router->post('/account/delete', static fn (Request $request, Container $container): Response => $securityController($container)->delete($request));
$router->get('/account/deleted', static fn (Request $request, Container $container): Response => $securityController($container)->deleted());

$emailController = static fn (Container $container): AccountEmailController => new AccountEmailController(
    $container->get(AuthManager::class), $container->get(AccountEmailService::class), new AccountEmailInput(),
    new DatabaseRateLimiter($container->get(\PDO::class), 3, 3600, 'account-email'),
    $container->get(ActivityLogger::class), $container->get(CsrfTokenManager::class), $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/account/email', static fn (Request $request, Container $container): Response => $emailController($container)->show());
$router->post('/account/email', static fn (Request $request, Container $container): Response => $emailController($container)->request($request));
$router->get('/account/email/verify/{token}', static fn (Request $request, Container $container): Response => $emailController($container)->verify($request));
