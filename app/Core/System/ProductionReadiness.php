<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Core\Config\ConfigRepository;
use NovaNuke\Core\Mail\MailConfigurationCheck;
use NovaNuke\Core\Mail\MailProductionReadiness;

final class ProductionReadiness
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly string $root,
    ) {
    }

    /** @return list<array{name:string,passed:bool,required:bool,detail:string}> */
    public function run(): array
    {
        $checks = [];
        $this->add($checks, 'PHP version', version_compare(PHP_VERSION, '8.3.0', '>='), true, 'Detected ' . PHP_VERSION . '.');

        $missing = array_values(array_filter(
            ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'fileinfo', 'dom'],
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));
        $this->add($checks, 'PHP extensions', $missing === [], true, $missing === [] ? 'All required extensions are loaded.' : 'Missing: ' . implode(', ', $missing));

        $this->add($checks, 'Production environment', $this->config->get('app.environment') === 'production', true, 'APP_ENV must be production.');
        $this->add($checks, 'Debug disabled', $this->config->get('app.debug') === false, true, 'APP_DEBUG must be false.');

        $url = (string) $this->config->get('app.url', '');
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        $this->add($checks, 'HTTPS URL', $https, true, 'APP_URL must use https://.');
        $this->add($checks, 'Secure session cookie', $this->config->get('session.secure') === true, true, 'SESSION_SECURE must be true.');

        $sameSite = (string) $this->config->get('session.same_site', 'Lax');
        $sameSiteSafe = in_array($sameSite, ['Lax', 'Strict'], true) || ($sameSite === 'None' && $this->config->get('session.secure') === true);
        $this->add($checks, 'Session SameSite policy', $sameSiteSafe, true, 'Use Lax or Strict; None requires a Secure cookie.');

        $lifetime = (int) $this->config->get('session.lifetime', 7200);
        $idleTimeout = (int) $this->config->get('session.idle_timeout', 1800);
        $rotationInterval = (int) $this->config->get('session.rotation_interval', 900);
        $this->add($checks, 'Session absolute lifetime', $lifetime >= 900 && $lifetime <= 86400, true, 'SESSION_LIFETIME should be between 900 and 86400 seconds.');
        $this->add($checks, 'Session idle timeout', $idleTimeout >= 300 && $idleTimeout <= 7200, true, 'SESSION_IDLE_TIMEOUT should be between 300 and 7200 seconds.');
        $this->add($checks, 'Session ID rotation', $rotationInterval >= 300 && $rotationInterval <= 1800, true, 'SESSION_ROTATION_INTERVAL should be between 300 and 1800 seconds.');

        $sessionPath = (string) $this->config->get('session.path', '/');
        $sessionDomain = (string) $this->config->get('session.domain', '');
        $sessionName = (string) $this->config->get('session.name', 'novanuke_session');
        $scopeSafe = $sessionPath === '/' && $sessionDomain === '';
        $this->add($checks, 'Session cookie scope', $scopeSafe, true, 'Production sessions should use SESSION_PATH=/ and an empty SESSION_DOMAIN.');
        $hostPrefix = str_starts_with($sessionName, '__Host-');
        $this->add($checks, 'Host-prefixed session cookie', $hostPrefix, false, $hostPrefix ? 'Session cookie uses the __Host- prefix.' : 'Recommended on HTTPS production: use a __Host- prefixed SESSION_NAME.');

        $this->add($checks, 'Security headers', $this->config->get('security.headers_enabled') === true, true, 'SECURITY_HEADERS_ENABLED must be true.');
        $hstsEnabled = $this->config->get('security.hsts_enabled') === true;
        $hstsMaxAge = (int) $this->config->get('security.hsts_max_age', 31536000);
        $this->add($checks, 'HSTS policy', $hstsEnabled && $hstsMaxAge >= 31536000 && $hstsMaxAge <= 63072000, false, $hstsEnabled ? 'HSTS is enabled with max-age=' . $hstsMaxAge . '.' : 'Recommended after HTTPS validation: enable HSTS with at least a one-year max-age.');
        $key = (string) $this->config->get('app.key', '');
        $this->add($checks, 'Application key', str_starts_with($key, 'base64:') && strlen($key) >= 50, true, 'APP_KEY must retain the generated secret.');

        $envPath = $this->root . '/.env';
        $envSecure = is_file($envPath) && ! is_link($envPath);
        $envDetail = $envSecure ? '.env exists as a regular file.' : '.env must exist as a regular file.';
        if ($envSecure && PHP_OS_FAMILY !== 'Windows') {
            $permissions = fileperms($envPath);
            $envSecure = $permissions !== false && (($permissions & 0077) === 0);
            $envDetail = $envSecure
                ? '.env is restricted to owner-only access.'
                : '.env permissions are too permissive; use 0600 or an equivalent owner-only policy.';
        }
        $this->add($checks, 'Environment file permissions', $envSecure, true, $envDetail);

        $writable = [];
        foreach (['storage/cache', 'storage/logs', 'storage/sessions', 'storage/private', 'public/uploads'] as $directory) {
            if (! is_dir($this->root . '/' . $directory) || ! is_writable($this->root . '/' . $directory)) {
                $writable[] = $directory;
            }
        }
        $this->add($checks, 'Writable directories', $writable === [], true, $writable === [] ? 'Only expected runtime directories were checked.' : 'Unavailable: ' . implode(', ', $writable));

        $publicHtaccess = $this->root . '/public/.htaccess';
        $uploadsHtaccess = $this->root . '/public/uploads/.htaccess';
        $privateHtaccess = $this->root . '/storage/private/.htaccess';
        $apacheGuards = is_file($publicHtaccess) && is_file($uploadsHtaccess) && is_file($privateHtaccess)
            && str_contains((string) file_get_contents($publicHtaccess), 'Options -Indexes')
            && str_contains((string) file_get_contents($uploadsHtaccess), 'Options -Indexes -ExecCGI')
            && str_contains((string) file_get_contents($privateHtaccess), 'Require all denied');
        $this->add($checks, 'Shared-hosting Apache guards', $apacheGuards, true, 'public/.htaccess, public/uploads/.htaccess and storage/private/.htaccess must retain the distribution hardening rules.');

        $this->add($checks, 'PHP display errors', filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL) !== true, true, 'display_errors must be Off for the production PHP handler.');
        $this->add($checks, 'PHP exposure', filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOL) !== true, false, 'Recommended: expose_php=Off.');
        $this->add($checks, 'OPcache', extension_loaded('Zend OPcache') || function_exists('opcache_get_status'), false, 'Recommended for production performance.');

        $mailReadiness = new MailProductionReadiness($this->config, new MailConfigurationCheck($this->config));
        foreach ($mailReadiness->run() as $check) {
            $checks[] = $check;
        }

        return $checks;
    }

    public function passed(): bool
    {
        foreach ($this->run() as $check) {
            if ($check['required'] && ! $check['passed']) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array{name:string,passed:bool,required:bool,detail:string}> $checks */
    private function add(array &$checks, string $name, bool $passed, bool $required, string $detail): void
    {
        $checks[] = compact('name', 'passed', 'required', 'detail');
    }
}
