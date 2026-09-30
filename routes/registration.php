<?php

declare(strict_types=1);

use NovaNuke\Auth\PasswordPolicy;
use NovaNuke\Auth\RegistrationController;
use NovaNuke\Auth\RegistrationService;
use NovaNuke\Auth\RegistrationValidator;
use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use NovaNuke\Core\View\ViewRenderer;

$registrationController = static function (Container $container): RegistrationController {
    $config = $container->get(ConfigRepository::class);
    return new RegistrationController(
        $container->get(RegistrationService::class),
        new RegistrationValidator(new PasswordPolicy()),
        new DatabaseRateLimiter($container->get(\PDO::class), 3, 900, 'registration'),
        new DatabaseRateLimiter($container->get(\PDO::class), 3, 3600, 'verification-resend'),
        $container->get(CsrfTokenManager::class),
        $container->get(ViewRenderer::class),
        (string) $config->get('app.locale', 'en'),
        (string) $config->get('app.timezone', 'UTC'),
        $container->get(\NovaNuke\Core\I18n\Translator::class),
    );
};

$router->get('/register', static fn (Request $request, Container $container): Response =>
    $registrationController($container)->show()
);
$router->post('/register', static fn (Request $request, Container $container): Response =>
    $registrationController($container)->register($request)
);
$router->get('/verify-email/{token}', static fn (Request $request, Container $container): Response =>
    $registrationController($container)->verify($request)
);
$router->get('/resend-verification', static fn (Request $request, Container $container): Response =>
    $registrationController($container)->resendForm()
);
$router->post('/resend-verification', static fn (Request $request, Container $container): Response =>
    $registrationController($container)->resend($request)
);
