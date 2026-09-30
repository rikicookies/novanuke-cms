<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\View\ViewRenderer;
use Throwable;
use NovaNuke\Core\Logging\SensitiveDataRedactor;
use NovaNuke\Core\I18n\Translator;

final class InstallerController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly RequirementsChecker $requirements,
        private readonly InstallationValidator $validator,
        private readonly InstallerService $installer,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function show(Request $request): Response
    {
        $checks = $this->requirements->check($this->rootPath);

        return Response::html($this->views->render('installer/install.twig', [
            'checks' => $checks,
            'requirements_passed' => $this->requirements->allPassed($checks),
            'csrf_token' => $this->csrf->token(),
            'errors' => [],
            'old' => $this->defaults(),
            'timezones' => $this->recommendedTimezones(),
        ]));
    }

    public function install(Request $request): Response
    {
        $input = $request->allInput();
        $checks = $this->requirements->check($this->rootPath);

        if (! $this->csrf->validate($input['_token'] ?? null)) {
            return Response::html($this->views->render('installer/error.twig', [
                'title' => $this->translate('installer.error.session_expired', [], 'Installation session expired'),
                'message' => $this->translate('installer.error.session_refresh', [], 'Refresh the installer and submit the form again.'),
            ]), 419);
        }

        $errors = $this->validator->validate($input);

        if (! $this->requirements->allPassed($checks)) {
            $errors['requirements'] = $this->translate('installer.error.requirements', [], 'Resolve the failed server requirements before installing.');
        }

        if ($errors !== []) {
            return $this->formWithErrors($checks, $errors, $input, 422);
        }

        $data = new InstallationData(
            trim((string) $input['site_name']),
            rtrim(trim((string) $input['site_url']), '/'),
            (string) $input['locale'],
            (string) $input['timezone'],
            trim((string) $input['database_host']),
            (int) $input['database_port'],
            (string) $input['database_name'],
            (string) $input['database_username'],
            (string) ($input['database_password'] ?? ''),
            trim((string) $input['admin_username']),
            strtolower(trim((string) $input['admin_email'])),
            (string) $input['admin_password'],
        );

        try {
            $migrations = $this->installer->install($data);
            $this->csrf->rotate();

            return Response::html($this->views->render('installer/complete.twig', [
                'site_name' => $data->siteName,
                'migrations_count' => count($migrations),
            ]));
        } catch (Throwable $error) {
            $reference = bin2hex(random_bytes(5));
            error_log(sprintf(
                "[%s] installer-%s %s: %s%s",
                gmdate('c'),
                $reference,
                $error::class,
                (new SensitiveDataRedactor())->redact($error->getMessage()),
                PHP_EOL,
            ), 3, $this->rootPath . '/storage/logs/novanuke.log');

            $errors['installation'] = $this->translate('installer.error.failed', ['reference' => $reference], "Installation failed. Reference: {$reference}. Check storage/logs/novanuke.log for the actual error.");

            return $this->formWithErrors($checks, $errors, $input, 500);
        }
    }

    /** @param list<array{name: string, passed: bool, detail: string}> $checks
     *  @param array<string, string> $errors
     *  @param array<string, mixed> $input
     */
    private function formWithErrors(array $checks, array $errors, array $input, int $status): Response
    {
        unset($input['database_password'], $input['admin_password'], $input['admin_password_confirmation']);

        return Response::html($this->views->render('installer/install.twig', [
            'checks' => $checks,
            'requirements_passed' => $this->requirements->allPassed($checks),
            'csrf_token' => $this->csrf->token(),
            'errors' => $errors,
            'old' => array_replace($this->defaults(), $input),
            'timezones' => $this->recommendedTimezones(),
        ]), $status);
    }

    /** @return array<string, string|int> */
    private function defaults(): array
    {
        return [
            'site_name' => 'NovaNuke',
            'site_url' => 'http://novanuke.test',
            'locale' => 'es',
            'timezone' => 'America/Los_Angeles',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'novanuke',
            'database_username' => 'root',
            'admin_username' => '',
            'admin_email' => '',
        ];
    }

    /** @return list<string> */
    private function recommendedTimezones(): array
    {
        return [
            'America/Los_Angeles',
            'America/Phoenix',
            'America/Denver',
            'America/Chicago',
            'America/New_York',
            'America/Mexico_City',
            'America/Mazatlan',
            'UTC',
        ];
    }

    /** @param array<string, scalar|null> $parameters */
    private function translate(string $key, array $parameters, string $fallback): string
    {
        return $this->translator?->translate($key, $parameters) ?? $fallback;
    }
}
