<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Core\Application;
use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Database\MigrationStatus;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Security\AuthorizationAudit;
use NovaNuke\Core\Mail\SmtpConfiguration;
use NovaNuke\Core\Settings\SettingsRepository;

final class SystemInspector
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ModuleManager $modules,
        private readonly string $rootPath,
        private readonly AuthorizationAudit $authorizationAudit,
        private readonly SettingsRepository $settings,
        private readonly MigrationStatus $migrations,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        $inventory = $this->modules->inventory();
        $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'fileinfo', 'dom'];
        $extensions = [];
        foreach ($requiredExtensions as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        $writable = [];
        foreach (['storage/cache', 'storage/logs', 'storage/sessions', 'storage/private'] as $directory) {
            $writable[$directory] = is_dir($this->rootPath . '/' . $directory)
                && is_writable($this->rootPath . '/' . $directory);
        }

        $environment = (string) $this->config->get('app.environment', 'production');
        $debug = (bool) $this->config->get('app.debug', false);
        $url = (string) $this->config->get('app.url', '');
        $warnings = [];
        if ($environment !== 'production') $warnings[] = 'APP_ENV is not production.';
        if ($debug) $warnings[] = 'APP_DEBUG must be disabled in production.';
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') $warnings[] = 'APP_URL does not use HTTPS.';
        $publicUrl = $this->settings->string('site.url', $url);
        if (rtrim($publicUrl, '/') !== rtrim($url, '/')) $warnings[] = 'The public site URL differs from APP_URL; update the deployment environment when the site address changes.';
        if (! (bool) $this->config->get('session.secure', false)) $warnings[] = 'SESSION_SECURE is disabled.';
        $appKey = (string) $this->config->get('app.key', '');
        if (! str_starts_with($appKey, 'base64:') || strlen($appKey) < 50) $warnings[] = 'APP_KEY is missing or does not have the expected generated format.';
        if ((string) $this->config->get('mail.mailer', 'log') === 'log') $warnings[] = 'The log mailer is for local development only.';
        $smtpConfigured = false;
        if ((string) $this->config->get('mail.mailer', 'log') === 'smtp') {
            try {
                new SmtpConfiguration(
                    (string) $this->config->get('mail.host', ''),
                    (int) $this->config->get('mail.port', 465),
                    (string) $this->config->get('mail.username', ''),
                    (string) $this->config->get('mail.password', ''),
                    strtolower((string) $this->config->get('mail.encryption', 'ssl')),
                    (int) $this->config->get('mail.timeout', 15),
                    (string) $this->config->get('mail.from_address', ''),
                    (string) $this->config->get('mail.from_name', 'NovaNuke'),
                );
                $smtpConfigured = true;
            } catch (\InvalidArgumentException) {
                $warnings[] = 'SMTP is selected but its configuration is incomplete or invalid.';
            }
        }
        if (in_array(false, $extensions, true)) $warnings[] = 'One or more required PHP extensions are missing.';
        if (in_array(false, $writable, true)) $warnings[] = 'One or more storage directories are not writable.';
        $migrationStatus = $this->migrations->inspect();
        if ($migrationStatus['pending_total'] > 0) $warnings[] = 'Database migrations are pending.';
        if ($migrationStatus['missing_total'] > 0) $warnings[] = 'Executed migration files are missing from this release.';
        if (($migrationStatus['recovery_total'] ?? 0) > 0) $warnings[] = 'An interrupted migration requires explicit recovery.';
        if ($migrationStatus['module_updates_total'] > 0) $warnings[] = 'Installed module updates are available.';

        return [
            'cms_version' => Application::VERSION,
            'php_version' => PHP_VERSION,
            'environment' => $environment,
            'maintenance' => $this->settings->boolean('system.maintenance', false),
            'debug' => $debug,
            'https_url' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https',
            'secure_session' => (bool) $this->config->get('session.secure', false),
            'security_headers' => (bool) $this->config->get('security.headers_enabled', true),
            'hsts' => (bool) $this->config->get('security.hsts_enabled', false),
            'mail_transport' => (string) $this->config->get('mail.mailer', 'log'),
            'smtp_configured' => $smtpConfigured,
            'extensions' => $extensions,
            'writable' => $writable,
            'modules_total' => count($inventory),
            'modules_enabled' => count(array_filter($inventory, static fn (array $module): bool => $module['enabled'])),
            'warnings' => $warnings,
            'authorization_audit' => $this->authorizationAudit->run(),
            'migrations' => $migrationStatus,
        ];
    }
}
