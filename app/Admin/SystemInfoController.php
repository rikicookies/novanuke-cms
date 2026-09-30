<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\System\SystemInspector;
use NovaNuke\Core\View\ViewRenderer;

final class SystemInfoController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly SystemInspector $inspector,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (! $this->authorization->allows((int) $user['id'], 'settings.manage')) {
            return Response::html($this->translator?->translate('admin.error.forbidden') ?? 'Forbidden', 403);
        }

        return Response::html($this->views->render('admin/system.twig', [
            'system' => $this->localized($this->inspector->inspect()),
        ]));
    }

    /** @param array<string,mixed> $system @return array<string,mixed> */
    private function localized(array $system): array
    {
        $warningKeys = [
            'APP_ENV is not production.' => 'environment',
            'APP_DEBUG must be disabled in production.' => 'debug',
            'APP_URL does not use HTTPS.' => 'https',
            'The public site URL differs from APP_URL; update the deployment environment when the site address changes.' => 'site_url',
            'SESSION_SECURE is disabled.' => 'secure_session',
            'APP_KEY is missing or does not have the expected generated format.' => 'app_key',
            'The log mailer is for local development only.' => 'log_mailer',
            'SMTP is selected but its configuration is incomplete or invalid.' => 'smtp',
            'One or more required PHP extensions are missing.' => 'extensions',
            'One or more storage directories are not writable.' => 'storage',
            'Database migrations are pending.' => 'migrations_pending',
            'Executed migration files are missing from this release.' => 'migrations_missing',
            'An interrupted migration requires explicit recovery.' => 'migration_recovery',
            'Installed module updates are available.' => 'module_updates',
        ];
        $system['warnings'] = array_map(function (mixed $warning) use ($warningKeys): string {
            $message = (string) $warning;
            $key = $warningKeys[$message] ?? null;
            return $key === null ? $message : ($this->translator?->translate('admin.system.warning.' . $key) ?? $message);
        }, is_array($system['warnings'] ?? null) ? $system['warnings'] : []);

        $labels = [
            'Active Super Administrator' => 'active_super',
            'Core permission catalog' => 'permission_catalog',
            'Super Administrator core permissions' => 'super_permissions',
            'Public roles' => 'public_roles',
            'Administrative role access' => 'admin_role_access',
        ];
        $audit = is_array($system['authorization_audit'] ?? null) ? $system['authorization_audit'] : [];
        foreach ($audit as &$check) {
            if (! is_array($check)) continue;
            $slug = $labels[(string) ($check['label'] ?? '')] ?? null;
            if ($slug === null) continue;
            $check['label'] = $this->translator?->translate('admin.system.audit.' . $slug) ?? (string) $check['label'];
            $check['detail'] = $this->auditDetail($slug, (bool) ($check['passed'] ?? false), (string) ($check['detail'] ?? ''));
        }
        unset($check);
        $system['authorization_audit'] = $audit;
        return $system;
    }

    private function auditDetail(string $slug, bool $passed, string $fallback): string
    {
        $parameters = [];
        if (preg_match('/^(\d+)/', $fallback, $match)) $parameters['count'] = $match[1];
        if (str_starts_with($fallback, 'Missing: ')) $parameters['items'] = substr($fallback, 9);
        $key = 'admin.system.audit.' . $slug . ($passed ? '.pass' : '.fail');
        return $this->translator?->translate($key, $parameters) ?? $fallback;
    }
}
