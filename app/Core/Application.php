<?php

declare(strict_types=1);

namespace NovaNuke\Core;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Auth\PasswordResetService;
use NovaNuke\Auth\RegistrationService;
use NovaNuke\Auth\ProfileRepository;
use NovaNuke\Auth\AvatarStorage;
use NovaNuke\Auth\AccountPasswordService;
use NovaNuke\Auth\AccountLifecycleService;
use NovaNuke\Auth\AccountSecurityRepository;
use NovaNuke\Auth\AccountEmailService;
use NovaNuke\Auth\LoginHistoryPresenter;
use NovaNuke\Auth\PasswordPolicy;
use NovaNuke\Core\Config\ConfigLoader;
use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Database\ConnectionFactory;
use NovaNuke\Core\Database\MigrationStatus;
use NovaNuke\Core\Database\Migrator;
use NovaNuke\Core\Http\ErrorHandler;
use NovaNuke\Core\Http\ErrorPageRenderer;
use NovaNuke\Core\Http\Kernel;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Core\Mail\LogMailer;
use NovaNuke\Core\Mail\MailDeliveryAcceptance;
use NovaNuke\Core\Mail\Mailer;
use NovaNuke\Core\Mail\SmtpConfiguration;
use NovaNuke\Core\Mail\MailConfigurationCheck;
use NovaNuke\Core\Mail\SmtpMailer;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use NovaNuke\Core\Security\RateLimiter;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Security\SecurityHeaders;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleHomepageRegistry;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Modules\ModulePackageInstaller;
use NovaNuke\Core\Modules\ModulePackageUploadValidator;
use NovaNuke\Core\Modules\ModuleRepository;
use NovaNuke\Core\Themes\ThemeAssetPublisher;
use NovaNuke\Core\Themes\ThemeDetector;
use NovaNuke\Core\Themes\ThemeDistributionCheck;
use NovaNuke\Core\Themes\ThemeManager;
use NovaNuke\Core\Themes\ThemeRepository;
use NovaNuke\Core\Blocks\BlockManager;
use NovaNuke\Core\Blocks\BlockRepository;
use NovaNuke\Core\Blocks\BlockVisibility;
use NovaNuke\Core\Blocks\MarkdownRenderer;
use NovaNuke\Core\Blocks\DynamicBlockRenderer;
use NovaNuke\Core\Logging\SensitiveDataRedactor;
use NovaNuke\Core\Security\HtmlSanitizer;
use NovaNuke\Core\Menus\MenuManager;
use NovaNuke\Core\Menus\MenuRepository;
use NovaNuke\Core\Menus\MenuTreeBuilder;
use NovaNuke\Core\Menus\MenuUrlResolver;
use NovaNuke\Core\System\SystemInspector;
use NovaNuke\Core\Backup\DatabaseBackup;
use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Core\Backup\BackupSetStatus;
use NovaNuke\Core\Backup\FileBackup;
use NovaNuke\Core\Backup\BackupVerifier;
use NovaNuke\Core\Backup\FileBackupRestorer;
use NovaNuke\Core\Backup\BackupRecoveryCheck;
use NovaNuke\Core\Backup\DatabaseRestoreVerifier;
use NovaNuke\Core\System\MaintenanceMode;
use NovaNuke\Core\System\PrivateSiteAccessPolicy;
use NovaNuke\Core\System\PasswordChangeAccessPolicy;
use NovaNuke\Core\Security\AuthorizationAudit;
use NovaNuke\Core\Security\AdminAccessGate;
use NovaNuke\Core\Cache\CacheManager;
use NovaNuke\Core\System\ReleaseChecklist;
use NovaNuke\Core\System\ProductionReadiness;
use NovaNuke\Core\System\DeploymentSecretCheck;
use NovaNuke\Core\System\ReleaseCandidateDeploymentCheck;
use NovaNuke\Core\System\DistributionSmokeCheck;
use NovaNuke\Core\System\InstalledSiteHealthCheck;
use NovaNuke\Core\Admin\AdminDashboardService;
use NovaNuke\Core\Admin\AdminNavigationManager;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\I18n\LocaleRegistry;
use NovaNuke\Core\Maintenance\DataPruner;
use NovaNuke\Core\Content\ContentRenderer;
use NovaNuke\Core\Content\ContentRendererInterface;
use NovaNuke\Core\Home\HomeContentResolver;
use NovaNuke\Core\Access\EntitlementService;
use NovaNuke\Core\Membership\MembershipService;
use NovaNuke\Core\Membership\MembershipManagerInterface;
use NovaNuke\Core\Membership\MembershipRepository;
use NovaNuke\Core\Membership\MembershipStatusPresenter;
use NovaNuke\Core\Membership\MembershipHealthCheck;
use NovaNuke\Core\Membership\MembershipPlanCatalog;
use NovaNuke\Core\Membership\MembershipExpirationProcessor;
use NovaNuke\Core\Membership\MembershipActivationProcessor;
use NovaNuke\Core\Billing\PaymentProviderRegistry;
use NovaNuke\Core\Billing\PaymentReceiptRepository;
use NovaNuke\Core\Billing\MembershipPaymentProvisioner;
use NovaNuke\Core\Billing\PaymentHealthCheck;
use NovaNuke\Core\Membership\MembershipProvisionerInterface;
use NovaNuke\Core\Access\AccessAudience;
use NovaNuke\Core\Modules\ModuleRouteAccess;
use PDO;

final class Application
{
    public const VERSION = Version::CURRENT;
    private bool $booted = false;

    private function __construct(
        private readonly string $rootPath,
        private readonly Container $container,
    ) {
    }

    public static function create(string $rootPath): self
    {
        $container = new Container();
        $config = (new ConfigLoader($rootPath . '/config'))->load();
        $installed = is_file($rootPath . '/storage/installed.lock');

        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

        $container->instance(self::class, $app = new self($rootPath, $container));
        $container->instance(ConfigRepository::class, $config);
        $container->instance(Router::class, new Router());
        $container->instance(EventDispatcher::class, new EventDispatcher());
        $container->instance(LocaleRegistry::class, new LocaleRegistry($rootPath . '/language'));
        $container->bind(SessionManager::class, static function () use ($config): SessionManager {
            $session = new SessionManager(
                (string) $config->get('session.name', 'novanuke_session'),
                (bool) $config->get('session.secure', false),
                (string) $config->get('session.same_site', 'Lax'),
                (int) $config->get('session.lifetime', 7200),
                (int) $config->get('session.idle_timeout', 1800),
                (int) $config->get('session.rotation_interval', 900),
                (string) $config->get('session.path', '/'),
                (string) $config->get('session.domain', ''),
            );
            $session->start();

            return $session;
        });
        $container->bind(CsrfTokenManager::class, static fn (Container $c) => new CsrfTokenManager(
            $c->get(SessionManager::class),
        ));
        $container->bind(PDO::class, static fn () => (new ConnectionFactory($config))->create());
        $container->bind(SettingsRepository::class, static fn (Container $c) => new SettingsRepository(
            $c->get(PDO::class),
        ));
        $container->bind(Translator::class, static fn () => new Translator(
            (string) $config->get('app.locale', 'en'),
            (string) $config->get('app.fallback_locale', 'en'),
            $rootPath . '/language',
        ));
        $container->bind(HomeContentResolver::class, static fn (Container $c) => new HomeContentResolver(
            $c->get(SettingsRepository::class),
            $c->get(Translator::class),
            $rootPath . '/language',
        ));
        $container->bind(Mailer::class, static function (Container $c) use ($config): Mailer {
            $mailer = (string) $config->get('mail.mailer', 'log');
            $siteIdentity = new \NovaNuke\Core\Mail\MailSiteIdentity(
                $c->get(SettingsRepository::class)->string(
                    'site.name',
                    (string) $config->get('app.name', 'NovaNuke'),
                ),
            );
            if ($mailer === 'log') {
                return new LogMailer(
                    (string) $config->require('mail.log_path'),
                    (string) $config->get('app.environment', 'production'),
                    (string) $config->get('mail.from_address', 'noreply@localhost'),
                    (string) $config->get('mail.from_name', 'NovaNuke'),
                    $siteIdentity,
                    $c->get(Translator::class),
                );
            }
            if ($mailer === 'smtp') {
                return new SmtpMailer(new SmtpConfiguration(
                    (string) $config->get('mail.host', ''),
                    (int) $config->get('mail.port', 465),
                    (string) $config->get('mail.username', ''),
                    (string) $config->get('mail.password', ''),
                    strtolower((string) $config->get('mail.encryption', 'ssl')),
                    (int) $config->get('mail.timeout', 15),
                    (string) $config->get('mail.from_address', ''),
                    (string) $config->get('mail.from_name', 'NovaNuke'),
                ), $siteIdentity, $c->get(Translator::class));
            }
            throw new \RuntimeException("Unsupported mailer: {$mailer}");
        });
        $container->bind(MailConfigurationCheck::class, static fn (Container $c) => new MailConfigurationCheck(
            $c->get(ConfigRepository::class),
        ));
        $container->bind(MailDeliveryAcceptance::class, static fn (Container $c) => new MailDeliveryAcceptance(
            $c->get(ConfigRepository::class),
            $rootPath . '/storage/private/mail-acceptance.json',
            $c->get(SettingsRepository::class)->string('site.url', (string) $config->get('app.url', 'http://localhost')),
            $c->get(MailConfigurationCheck::class),
        ));
        $container->bind(PasswordResetService::class, static fn (Container $c) => new PasswordResetService(
            $c->get(PDO::class),
            $c->get(Mailer::class),
            $c->get(SettingsRepository::class)->string('site.url', (string) $config->get('app.url', 'http://localhost')),
        ));
        $container->bind(RegistrationService::class, static fn (Container $c) => new RegistrationService(
            $c->get(PDO::class),
            $c->get(SettingsRepository::class),
            $c->get(Mailer::class),
            $c->get(EventDispatcher::class),
            $c->get(SettingsRepository::class)->string('site.url', (string) $config->get('app.url', 'http://localhost')),
        ));
        $container->bind(ProfileRepository::class, static fn (Container $c) => new ProfileRepository($c->get(PDO::class)));
        $container->bind(AvatarStorage::class, static fn () => new AvatarStorage($rootPath . '/storage/private/avatars'));
        $container->bind(AccountPasswordService::class, static fn (Container $c) => new AccountPasswordService(
            $c->get(PDO::class), new PasswordPolicy(),
        ));
        $container->bind(AccountSecurityRepository::class, static fn (Container $c) => new AccountSecurityRepository(
            $c->get(PDO::class), new LoginHistoryPresenter(),
        ));
        $container->bind(AccountLifecycleService::class, static fn (Container $c) => new AccountLifecycleService(
            $c->get(PDO::class), $c->get(EventDispatcher::class),
        ));
        $container->bind(AccountEmailService::class, static fn (Container $c) => new AccountEmailService(
            $c->get(PDO::class), $c->get(Mailer::class), $c->get(EventDispatcher::class),
            $c->get(SettingsRepository::class)->string('site.url', (string) $config->get('app.url', 'http://localhost')),
        ));
        $container->bind(AuthManager::class, static fn (Container $c) => new AuthManager(
            $c->get(PDO::class),
            $c->get(SessionManager::class),
            $c->get(EventDispatcher::class),
        ));
        $container->bind(RateLimiter::class, static fn (Container $c) => new DatabaseRateLimiter(
            $c->get(PDO::class),
            5,
            300,
            'login',
        ));
        $container->bind(AuthorizationService::class, static fn (Container $c) => new AuthorizationService(
            $c->get(PDO::class),
        ));
        $container->bind(EntitlementService::class, static fn (Container $c) => new EntitlementService($c->get(PDO::class)));
        $container->bind(MembershipPlanCatalog::class, static fn () => new MembershipPlanCatalog());
        $container->bind(MembershipService::class, static fn (Container $c) => new MembershipService($c->get(EntitlementService::class), $c->get(MembershipPlanCatalog::class), $c->get(EventDispatcher::class)));
        $container->bind(MembershipRepository::class, static fn (Container $c) => new MembershipRepository($c->get(PDO::class)));
        $container->bind(MembershipStatusPresenter::class, static fn () => new MembershipStatusPresenter());
        $container->bind(MembershipHealthCheck::class, static fn (Container $c) => new MembershipHealthCheck($c->get(PDO::class)));
        $container->bind(MembershipActivationProcessor::class, static fn (Container $c) => new MembershipActivationProcessor($c->get(PDO::class), $c->get(EventDispatcher::class)));
        $container->bind(MembershipExpirationProcessor::class, static fn (Container $c) => new MembershipExpirationProcessor($c->get(PDO::class), $c->get(EventDispatcher::class)));
        $container->bind(MembershipManagerInterface::class, static fn (Container $c) => $c->get(MembershipService::class));
        $container->bind(MembershipProvisionerInterface::class, static fn (Container $c) => $c->get(MembershipService::class));
        $container->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry());
        $container->bind(PaymentReceiptRepository::class, static fn (Container $c) => new PaymentReceiptRepository($c->get(PDO::class)));
        $container->bind(MembershipPaymentProvisioner::class, static fn (Container $c) => new MembershipPaymentProvisioner(
            $c->get(PDO::class),
            $c->get(PaymentProviderRegistry::class),
            $c->get(PaymentReceiptRepository::class),
            $c->get(MembershipProvisionerInterface::class),
        ));
        $container->bind(PaymentHealthCheck::class, static fn (Container $c) => new PaymentHealthCheck(
            $c->get(PDO::class),
            $c->get(MembershipPlanCatalog::class),
            $c->get(PaymentProviderRegistry::class),
        ));

        $container->bind(AccessAudience::class, static fn (Container $c) => new AccessAudience($c->get(MembershipManagerInterface::class)));
        $container->bind(ModuleRouteAccess::class, static fn (Container $c) => new ModuleRouteAccess(new ModuleRepository($c->get(PDO::class)), $c->get(AccessAudience::class), $c->get(AuthManager::class), $c->get(Translator::class)));
        $container->bind(ContentRendererInterface::class, static fn () => new ContentRenderer(
            new HtmlSanitizer(),
            new MarkdownRenderer(new HtmlSanitizer()),
        ));
        $container->bind(ActivityLogger::class, static fn (Container $c) => new ActivityLogger(
            $c->get(PDO::class),
        ));
        $container->bind(ModuleRepository::class, static fn (Container $c) => new ModuleRepository($c->get(PDO::class)));
        $container->bind(ModulePackageInstaller::class, static fn (Container $c) => new ModulePackageInstaller(
            $rootPath . '/modules',
            new ModuleCompatibilityChecker(self::VERSION),
        ));
        $container->bind(ModulePackageUploadValidator::class, static fn () => new ModulePackageUploadValidator());
        $container->bind(ModuleManager::class, static fn (Container $c) => new ModuleManager(
            $c->get(PDO::class),
            new ModuleDetector($rootPath . '/modules'),
            $c->get(ModuleRepository::class),
            new ModuleMigrator($c->get(PDO::class)),
            new ModuleCompatibilityChecker(self::VERSION),
            $c,
            $c->get(Router::class),
            $c->get(EventDispatcher::class),
            $c->get(Translator::class),
        ));
        $container->bind(ModuleHomepageRegistry::class, static fn (Container $c) => new ModuleHomepageRegistry(
            static fn (): array => $c->get(ModuleManager::class)->inventory(),
            static fn (string $key): bool => $c->get(SettingsRepository::class)->boolean($key, false),
        ));
        $container->bind(\NovaNuke\Core\Modules\ModulesMenuBuilder::class, static fn (Container $c) => new \NovaNuke\Core\Modules\ModulesMenuBuilder(
            $c->get(ModuleManager::class),
            $c->get(AuthManager::class),
            $c->get(AccessAudience::class),
            $c->get(Translator::class),
            $c->get(SettingsRepository::class),
        ));
        $container->bind(MigrationStatus::class, static fn (Container $c) => new MigrationStatus(
            new Migrator($c->get(PDO::class)),
            new ModuleMigrator($c->get(PDO::class)),
            $c->get(ModuleManager::class),
            $rootPath . '/database/migrations',
        ));
        $container->bind(ThemeManager::class, static fn (Container $c) => new ThemeManager(
            new ThemeDetector($rootPath . '/themes'),
            new ThemeRepository($c->get(PDO::class)),
            new ThemeAssetPublisher($rootPath . '/public/assets/themes'),
            $c->get(SettingsRepository::class),
            $c->get(ViewRenderer::class),
            $c->get(EventDispatcher::class),
            $c->get(Translator::class),
            self::VERSION,
        ));
        $container->bind(BlockManager::class, static fn (Container $c) => new BlockManager(
            $c->get(PDO::class),
            new BlockRepository($c->get(PDO::class)),
            new HtmlSanitizer(),
            new MarkdownRenderer(new HtmlSanitizer()),
            new DynamicBlockRenderer($c->get(EventDispatcher::class), new SensitiveDataRedactor()),
            new BlockVisibility(),
            $c->get(AuthManager::class),
            $c->get(AccessAudience::class),
            $c->get(ViewRenderer::class),
        ));
        $container->bind(MenuManager::class, static fn (Container $c) => new MenuManager(
            $c->get(PDO::class),
            new MenuRepository($c->get(PDO::class)),
            new MenuUrlResolver(),
            new MenuTreeBuilder(),
            $c->get(AuthManager::class),
            $c->get(ModuleRouteAccess::class),
            $c->get(ViewRenderer::class),
        ));
        $container->bind(ViewRenderer::class, static function (Container $c) use ($rootPath, $config): ViewRenderer {
            $views = new ViewRenderer(
                $rootPath . '/resources/views',
                $rootPath . '/storage/cache/twig',
                (bool) $config->get('app.debug', false),
                $c->get(Translator::class),
            );
            // Core admin views live in a protected namespace. Public themes may
            // override public presentation, but they can never shadow Admin UI.
            $views->addNamespace('admin-core', $rootPath . '/resources/views');
            // Error pages remain core-owned and independent of active themes.
            $views->addNamespace('error-core', $rootPath . '/resources/views');
            return $views;
        });
        $container->bind(ErrorPageRenderer::class, static fn (Container $c) => new ErrorPageRenderer(
            $c->get(ViewRenderer::class),
        ));
        $container->bind(ErrorHandler::class, static fn (Container $c) => new ErrorHandler(
            (bool) $config->get('app.debug', false),
            $rootPath . '/storage/logs/novanuke.log',
            projectRoot: $rootPath,
            translator: $c->get(Translator::class),
            pageRenderer: $c->get(ErrorPageRenderer::class),
        ));
        $container->bind(SecurityHeaders::class, static fn () => new SecurityHeaders(
            (bool) $config->get('security.headers_enabled', true),
            (bool) $config->get('security.hsts_enabled', false),
            (int) $config->get('security.hsts_max_age', 31536000),
            (string) $config->get('app.url', 'http://localhost'),
            (string) $config->get('app.environment', 'production'),
        ));
        $container->bind(SystemInspector::class, static fn (Container $c) => new SystemInspector(
            $config,
            $c->get(ModuleManager::class),
            $rootPath,
            $c->get(AuthorizationAudit::class),
            $c->get(SettingsRepository::class),
            $c->get(MigrationStatus::class),
        ));
        $container->bind(AuthorizationAudit::class, static fn (Container $c) => new AuthorizationAudit(
            $c->get(PDO::class),
        ));
        $container->bind(AdminAccessGate::class, static fn (Container $c) => new AdminAccessGate($c->get(Translator::class)));
        $container->bind(DatabaseBackup::class, static fn (Container $c) => new DatabaseBackup(
            $c->get(PDO::class),
            $rootPath . '/storage/private/backups',
        ));
        $container->bind(BackupSetCoordinator::class, static fn (Container $c) => new BackupSetCoordinator(
            $c->get(PDO::class),
            $rootPath,
            $rootPath . '/storage/private/backups',
        ));
        $container->bind(BackupSetStatus::class, static fn () => new BackupSetStatus(
            $rootPath . '/storage/private/backups',
        ));
        $container->bind(FileBackup::class, static fn () => new FileBackup(
            $rootPath,
            $rootPath . '/storage/private/backups',
        ));
        $container->bind(BackupVerifier::class, static fn () => new BackupVerifier($rootPath . '/storage/private/backups'));
        $container->bind(FileBackupRestorer::class, static fn (Container $c) => new FileBackupRestorer($c->get(BackupVerifier::class)));
        $container->bind(BackupRecoveryCheck::class, static function (Container $c) use ($rootPath): BackupRecoveryCheck {
            $restore = null;
            $dsn = trim((string) env('NOVANUKE_BACKUP_VERIFY_DSN', ''));
            if ($dsn !== '') {
                $restoreDatabase = new PDO(
                    $dsn,
                    (string) env('NOVANUKE_BACKUP_VERIFY_USERNAME', ''),
                    (string) env('NOVANUKE_BACKUP_VERIFY_PASSWORD', ''),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ],
                );
                $restore = new DatabaseRestoreVerifier($restoreDatabase);
            }
            return new BackupRecoveryCheck(
                $rootPath . '/storage/private/backups',
                $c->get(BackupVerifier::class),
                $c->get(FileBackupRestorer::class),
                $restore,
            );
        });
        $container->bind(CacheManager::class, static fn () => new CacheManager(
            $rootPath . '/storage/cache',
        ));
        $container->bind(ReleaseChecklist::class, static fn () => new ReleaseChecklist($rootPath));
        $container->bind(ProductionReadiness::class, static fn (Container $c) => new ProductionReadiness($c->get(ConfigRepository::class), $rootPath));
        $container->bind(ThemeDistributionCheck::class, static fn () => new ThemeDistributionCheck($rootPath . '/themes'));
        $container->bind(DeploymentSecretCheck::class, static fn (Container $c) => new DeploymentSecretCheck($c->get(ConfigRepository::class)));
        $container->bind(ReleaseCandidateDeploymentCheck::class, static fn (Container $c) => new ReleaseCandidateDeploymentCheck(
            $c->get(InstalledSiteHealthCheck::class),
            $c->get(ProductionReadiness::class),
            $c->get(BackupRecoveryCheck::class),
            $c->get(AuthorizationAudit::class),
            $c->get(MembershipHealthCheck::class),
            $c->get(PaymentHealthCheck::class),
            $c->get(MailConfigurationCheck::class),
            $c->get(MailDeliveryAcceptance::class),
            $c->get(ThemeDistributionCheck::class),
            $c->get(DeploymentSecretCheck::class),
        ));

        $container->bind(DistributionSmokeCheck::class, static fn () => new DistributionSmokeCheck($rootPath));
        $container->bind(InstalledSiteHealthCheck::class, static fn (Container $c) => new InstalledSiteHealthCheck(
            $rootPath,
            $c->get(SettingsRepository::class),
            $c->get(MigrationStatus::class),
        ));
        $container->bind(DataPruner::class, static fn (Container $c) => new DataPruner(
            $c->get(PDO::class), $c->get(EventDispatcher::class),
            $c->get(MembershipActivationProcessor::class), $c->get(MembershipExpirationProcessor::class),
        ));
        $container->bind(AdminDashboardService::class, static fn (Container $c) => new AdminDashboardService(
            $c->get(PDO::class),
            $c->get(ModuleManager::class),
        ));
        $container->bind(AdminNavigationManager::class, static fn (Container $c) => new AdminNavigationManager(
            $c->get(AuthManager::class),
            $c->get(AuthorizationService::class),
            $c->get(EventDispatcher::class),
            $c->get(ViewRenderer::class),
            $c->get(SettingsRepository::class),
        ));
        $container->bind(MaintenanceMode::class, static fn (Container $c) => new MaintenanceMode(
            $c->get(SettingsRepository::class),
            $c->get(AuthManager::class),
            is_file($rootPath . '/storage/installed.lock'),
        ));
        $container->bind(Kernel::class, static fn (Container $c) => new Kernel(
            $c,
            $c->get(Router::class),
            $c->get(ErrorHandler::class),
            $c->get(SecurityHeaders::class),
            $installed ? $c->get(MaintenanceMode::class) : null,
            $installed ? $c->get(AdminAccessGate::class) : null,
            new PrivateSiteAccessPolicy(),
            new PasswordChangeAccessPolicy(),
            $installed ? $c->get(ModuleRouteAccess::class) : null,
            $installed,
            $c->get(Translator::class),
        ));

        $container->get(ViewRenderer::class)->addGlobal('cms_version', self::VERSION);
        $container->get(ViewRenderer::class)->addGlobal('cms_locales', $container->get(LocaleRegistry::class)->all());

        return $app;
    }

    public function boot(): void
    {
        if ($this->booted) return;
        $this->booted = true;
        $this->loadRoutes();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function kernel(): Kernel
    {
        return $this->container->get(Kernel::class);
    }

    private function loadRoutes(): void
    {
        $router = $this->container->get(Router::class);
        $container = $this->container;
        $installed = is_file($this->rootPath . '/storage/installed.lock');

        if (! $installed) {
            require $this->rootPath . '/routes/installer.php';
            return;
        }

        require $this->rootPath . '/routes/web.php';
        require $this->rootPath . '/routes/auth.php';
        require $this->rootPath . '/routes/passwords.php';
        require $this->rootPath . '/routes/registration.php';
        require $this->rootPath . '/routes/account.php';
        require $this->rootPath . '/routes/forms.php';
        require $this->rootPath . '/routes/admin.php';
        $settings = $this->container->get(SettingsRepository::class);
        $views = $this->container->get(ViewRenderer::class);
        $timezone = $settings->string('site.timezone', (string) $this->container->get(ConfigRepository::class)->get('app.timezone', 'UTC'));
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }
        $locale = $settings->string('site.locale', 'en');
        $locales = $this->container->get(LocaleRegistry::class);
        $locale = $locales->fallback($locale);
        $authenticatedUser = $this->container->get(AuthManager::class)->user();
        if ($authenticatedUser !== null) {
            $profile = $this->container->get(ProfileRepository::class)->byUserId((int) $authenticatedUser['id']);
            if ($profile !== null && $locales->supports((string) $profile['locale'])) $locale = (string) $profile['locale'];
            if ($profile !== null && in_array($profile['timezone'], timezone_identifiers_list(), true)) $timezone = $profile['timezone'];
        }
        $this->container->get(Translator::class)->setLocale($locale);
        $dateFormat = $settings->string('site.date_format', 'F j, Y');
        if (! isset(\NovaNuke\Core\Settings\GeneralSettingsInput::DATE_FORMATS[$dateFormat])) {
            $dateFormat = 'F j, Y';
        }
        date_default_timezone_set($timezone);
        $views->addGlobal('cms_name', $settings->string('site.name', 'NovaNuke'));
        $views->addGlobal('cms_url', $settings->string('site.url', ''));
        $views->addGlobal('cms_locale', $locale);
        $views->addGlobal('cms_description', $settings->string('site.description', 'A modern modular CMS with an old-school spirit.'));
        $views->addGlobal('cms_admin_email', $settings->string('site.admin_email', ''));
        $views->addGlobal('cms_timezone', $timezone);
        $views->addGlobal('cms_date_format', $dateFormat);
        $views->addGlobal('cms_per_page', $settings->integer('site.per_page', 10, 5, 100));
        $views->addGlobal('current_user', $authenticatedUser);
        $views->addGlobal('csrf_token', $this->container->get(\NovaNuke\Core\Security\CsrfTokenManager::class)->token());
        $views->addGlobal('contact_form_action', '/forms/contact');
        $this->container->get(ThemeManager::class)->bootActive();
        $this->container->get(ModuleManager::class)->bootEnabled();
        $this->container->get(EventDispatcher::class)->listen(
            \NovaNuke\Core\Events\EventName::BLOCK_RENDERING,
            function (object $event) use ($views): void {
                if (! $event instanceof \NovaNuke\Core\Blocks\BlockRendering || ($event->block['type'] ?? '') !== 'modules-menu') return;
                $event->render($views->render('components/modules-menu.twig', [
                    'items' => $this->container->get(\NovaNuke\Core\Modules\ModulesMenuBuilder::class)->items(),
                ]));
            },
            100,
        );
        $this->container->get(AdminNavigationManager::class)->boot();
        $this->container->get(MenuManager::class)->boot();
        $this->container->get(BlockManager::class)->boot();
    }
}
