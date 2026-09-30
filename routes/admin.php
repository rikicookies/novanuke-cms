<?php

declare(strict_types=1);

use NovaNuke\Auth\AuthManager;
use NovaNuke\Admin\UserSettingsController;
use NovaNuke\Admin\RolesController;
use NovaNuke\Admin\UsersController;
use NovaNuke\Admin\MembershipsController;
use NovaNuke\Admin\ActivityLogsController;
use NovaNuke\Admin\ModulesController;
use NovaNuke\Admin\ThemesController;
use NovaNuke\Admin\BlocksController;
use NovaNuke\Admin\MenusController;
use NovaNuke\Admin\SystemInfoController;
use NovaNuke\Admin\AdminDashboardController;
use NovaNuke\Admin\GeneralSettingsController;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleHomepageRegistry;
use NovaNuke\Core\Themes\ThemeManager;
use NovaNuke\Core\Blocks\BlockManager;
use NovaNuke\Core\Menus\MenuManager;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\System\SystemInspector;
use NovaNuke\Core\Admin\AdminDashboardService;
use NovaNuke\Core\Admin\DashboardHealthSummary;
use NovaNuke\Core\Settings\GeneralSettingsInput;
use NovaNuke\Core\Home\HomeContentResolver;
use NovaNuke\Core\I18n\LocaleRegistry;
use NovaNuke\Auth\RegistrationValidator;
use NovaNuke\Auth\PasswordPolicy;
use NovaNuke\Core\Membership\MembershipRepository;
use NovaNuke\Core\Membership\MembershipService;
use NovaNuke\Core\Membership\MembershipManagerInterface;
use NovaNuke\Core\Membership\MembershipStatusPresenter;

$dashboardController = static fn (Container $container): AdminDashboardController => new AdminDashboardController(
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(AdminDashboardService::class),
    $container->get(SystemInspector::class),
    $container->get(EventDispatcher::class),
    $container->get(CsrfTokenManager::class),
    $container->get(ViewRenderer::class),
    new DashboardHealthSummary(),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin', static fn (Request $request, Container $container): Response =>
    $dashboardController($container)->index()
);

$generalSettingsController = static fn (Container $container): GeneralSettingsController => new GeneralSettingsController(
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(SettingsRepository::class),
    new GeneralSettingsInput($container->get(LocaleRegistry::class)),
    $container->get(HomeContentResolver::class),
    $container->get(ModuleHomepageRegistry::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(SessionManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/settings', static fn (Request $request, Container $container): Response =>
    $generalSettingsController($container)->show()
);
$router->post('/admin/settings', static fn (Request $request, Container $container): Response =>
    $generalSettingsController($container)->update($request)
);

$userSettingsController = static fn (Container $container): UserSettingsController => new UserSettingsController(
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(SettingsRepository::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/settings/users', static fn (Request $request, Container $container): Response =>
    $userSettingsController($container)->show()
);
$router->post('/admin/settings/users', static fn (Request $request, Container $container): Response =>
    $userSettingsController($container)->update($request)
);

$rolesController = static fn (Container $container): RolesController => new RolesController(
    $container->get(\PDO::class),
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/roles', static fn (Request $request, Container $container): Response =>
    $rolesController($container)->index()
);
$router->get('/admin/roles/{id}', static fn (Request $request, Container $container): Response =>
    $rolesController($container)->edit($request)
);
$router->post('/admin/roles/{id}', static fn (Request $request, Container $container): Response =>
    $rolesController($container)->update($request)
);

$usersController = static fn (Container $container): UsersController => new UsersController(
    $container->get(\PDO::class),
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(ViewRenderer::class),
    new RegistrationValidator(new PasswordPolicy()),
    new PasswordPolicy(),
    $container->get(MembershipManagerInterface::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/users', static fn (Request $request, Container $container): Response =>
    $usersController($container)->index($request)
);
$router->get('/admin/users/create', static fn (Request $request, Container $container): Response =>
    $usersController($container)->create()
);
$router->post('/admin/users', static fn (Request $request, Container $container): Response =>
    $usersController($container)->store($request)
);
$router->get('/admin/users/{id}', static fn (Request $request, Container $container): Response =>
    $usersController($container)->edit($request)
);
$router->post('/admin/users/{id}', static fn (Request $request, Container $container): Response =>
    $usersController($container)->update($request)
);
$router->post('/admin/users/{id}/password', static fn (Request $request, Container $container): Response =>
    $usersController($container)->resetPassword($request)
);
$router->post('/admin/users/{id}/membership', static fn (Request $request, Container $container): Response =>
    $usersController($container)->assignMembership($request)
);
$router->post('/admin/users/{id}/vip', static fn (Request $request, Container $container): Response =>
    $usersController($container)->grantVip($request)
);
$router->post('/admin/users/{id}/vip/revoke', static fn (Request $request, Container $container): Response =>
    $usersController($container)->revokeVip($request)
);


$membershipsController = static fn (Container $container): MembershipsController => new MembershipsController(
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(MembershipRepository::class),
    $container->get(MembershipService::class),
    $container->get(MembershipStatusPresenter::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/memberships', static fn (Request $request, Container $container): Response =>
    $membershipsController($container)->index($request)
);
$router->get('/admin/memberships/{id}', static fn (Request $request, Container $container): Response =>
    $membershipsController($container)->show($request)
);
$router->post('/admin/memberships/{id}/assign', static fn (Request $request, Container $container): Response =>
    $membershipsController($container)->assign($request)
);
$router->post('/admin/memberships/{id}/extend', static fn (Request $request, Container $container): Response =>
    $membershipsController($container)->extend($request)
);
$router->post('/admin/memberships/{id}/schedule', static fn (Request $request, Container $container): Response =>
    $membershipsController($container)->schedule($request)
);
$router->post('/admin/memberships/{id}/schedule/cancel', static fn (Request $request, Container $container): Response =>
    $membershipsController($container)->cancelScheduled($request)
);
$router->post('/admin/memberships/{id}/revoke', static fn (Request $request, Container $container): Response =>
    $membershipsController($container)->revoke($request)
);

$logsController = static fn (Container $container): ActivityLogsController => new ActivityLogsController(
    $container->get(\PDO::class),
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/logs', static fn (Request $request, Container $container): Response =>
    $logsController($container)->index()
);

$systemController = static fn (Container $container): SystemInfoController => new SystemInfoController(
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(SystemInspector::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/system', static fn (Request $request, Container $container): Response =>
    $systemController($container)->index()
);

$modulesController = static fn (Container $container): ModulesController => new ModulesController(
    $container->get(ModuleManager::class),
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
    $container->get(\NovaNuke\Core\Modules\ModulePackageInstaller::class),
    $container->get(\NovaNuke\Core\Modules\ModulePackageUploadValidator::class),
    $container->get(\NovaNuke\Core\Modules\ModuleRepository::class),
);
$router->get('/admin/modules', static fn (Request $request, Container $container): Response =>
    $modulesController($container)->index()
);
$router->post('/admin/modules/package', static fn (Request $request, Container $container): Response =>
    $modulesController($container)->upload($request)
);
$router->post('/admin/modules/{slug}/audience', static fn (Request $request, Container $container): Response =>
    $modulesController($container)->audience($request)
);
$router->post('/admin/modules/{slug}/{action}', static fn (Request $request, Container $container): Response =>
    $modulesController($container)->action($request)
);

$themesController = static fn (Container $container): ThemesController => new ThemesController(
    $container->get(ThemeManager::class),
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(SessionManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/themes', static fn (Request $request, Container $container): Response =>
    $themesController($container)->index()
);
$router->post('/admin/themes/{slug}/{action}', static fn (Request $request, Container $container): Response =>
    $themesController($container)->action($request)
);

$blocksController = static fn (Container $container): BlocksController => new BlocksController(
    $container->get(BlockManager::class),
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(SessionManager::class),
    $container->get(ViewRenderer::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/blocks', static fn (Request $request, Container $container): Response =>
    $blocksController($container)->index()
);
$router->post('/admin/blocks/save', static fn (Request $request, Container $container): Response =>
    $blocksController($container)->save($request)
);
$router->post('/admin/blocks/{id}/delete', static fn (Request $request, Container $container): Response =>
    $blocksController($container)->delete($request)
);

$menusController = static fn (Container $container): MenusController => new MenusController(
    $container->get(MenuManager::class),
    $container->get(AuthManager::class),
    $container->get(AuthorizationService::class),
    $container->get(ActivityLogger::class),
    $container->get(CsrfTokenManager::class),
    $container->get(SessionManager::class),
    $container->get(ViewRenderer::class),
    $container->get(SettingsRepository::class),
    $container->get(\NovaNuke\Core\I18n\Translator::class),
);
$router->get('/admin/menus', static fn (Request $request, Container $container): Response =>
    $menusController($container)->index()
);
$router->post('/admin/menus/save', static fn (Request $request, Container $container): Response =>
    $menusController($container)->saveMenu($request)
);
$router->post('/admin/menu-items/save', static fn (Request $request, Container $container): Response =>
    $menusController($container)->saveItem($request)
);
$router->post('/admin/menus/{id}/order', static fn (Request $request, Container $container): Response =>
    $menusController($container)->savePublicMenuOrder($request)
);
$router->post('/admin/navigation/order', static fn (Request $request, Container $container): Response =>
    $menusController($container)->saveAdminNavigationOrder($request)
);
$router->post('/admin/menus/{id}/delete', static fn (Request $request, Container $container): Response =>
    $menusController($container)->deleteMenu($request)
);
$router->post('/admin/menu-items/{id}/delete', static fn (Request $request, Container $container): Response =>
    $menusController($container)->deleteItem($request)
);
