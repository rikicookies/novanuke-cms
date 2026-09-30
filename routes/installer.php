<?php

declare(strict_types=1);

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Installer\EnvWriter;
use NovaNuke\Installer\InstallationValidator;
use NovaNuke\Installer\InstallerController;
use NovaNuke\Installer\InstallerService;
use NovaNuke\Installer\RequirementsChecker;
use NovaNuke\Core\I18n\LocaleRegistry;
use NovaNuke\Core\I18n\Translator;

$installerRoot = dirname(__DIR__);

$controller = static function (Container $container) use ($installerRoot): InstallerController {
    return new InstallerController(
        $installerRoot,
        new RequirementsChecker($container->get(Translator::class)),
        new InstallationValidator($container->get(LocaleRegistry::class), $container->get(Translator::class)),
        new InstallerService($installerRoot, new EnvWriter()),
        $container->get(CsrfTokenManager::class),
        $container->get(ViewRenderer::class),
        $container->get(Translator::class),
    );
};

$noStore = static fn (Response $response): Response => $response
    ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
    ->withHeader('Pragma', 'no-cache')
    ->withHeader('Expires', '0');

$router->get('/', static fn (): Response => $noStore(Response::redirect('/install')));
$router->get('/install', static fn (Request $request, Container $container): Response =>
    $noStore($controller($container)->show($request))
);
$router->post('/install', static fn (Request $request, Container $container): Response =>
    $noStore($controller($container)->install($request))
);
