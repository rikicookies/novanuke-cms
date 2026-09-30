<?php

declare(strict_types=1);
use NovaNuke\Core\Container\Container; use NovaNuke\Core\Forms\ContactFormController; use NovaNuke\Core\Http\Request; use NovaNuke\Core\Http\Response; use NovaNuke\Core\Mail\Mailer; use NovaNuke\Core\Security\CsrfTokenManager; use NovaNuke\Core\Settings\SettingsRepository;
$router->post('/forms/contact', static function(Request $request, Container $container): Response { return (new ContactFormController($container->get(CsrfTokenManager::class),$container->get(Mailer::class),$container->get(SettingsRepository::class),$container->get(PDO::class)))->submit($request); }, 'forms.contact');
