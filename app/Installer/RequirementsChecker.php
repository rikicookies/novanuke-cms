<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use NovaNuke\Core\I18n\Translator;

final class RequirementsChecker
{
    public function __construct(private readonly ?Translator $translator = null) {}

    /** @return list<array{name: string, passed: bool, detail: string}> */
    public function check(string $rootPath): array
    {
        $storage = new StorageProvisioner();
        $storage->provision($rootPath);

        $checks = [[
            'name' => 'PHP 8.3+',
            'passed' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'detail' => $this->translate('installer.requirement.detected', ['version' => PHP_VERSION], 'Detected ' . PHP_VERSION),
        ]];

        foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'fileinfo', 'dom'] as $extension) {
            $checks[] = [
                'name' => $this->translate('installer.requirement.extension', ['extension' => $extension], "Extension {$extension}"),
                'passed' => extension_loaded($extension),
                'detail' => extension_loaded($extension)
                    ? $this->translate('installer.requirement.available', [], 'Available')
                    : $this->translate('installer.requirement.missing', [], 'Missing'),
            ];
        }

        foreach ($storage->requiredDirectories() as $directory) {
            $path = $rootPath . '/' . $directory;
            $checks[] = [
                'name' => $this->translate('installer.requirement.writable_path', ['path' => $directory], "Writable {$directory}"),
                'passed' => is_dir($path) && is_writable($path),
                'detail' => is_writable($path)
                    ? $this->translate('installer.requirement.writable', [], 'Writable')
                    : $this->translate('installer.requirement.not_writable', [], 'Not writable'),
            ];
        }

        $checks[] = [
            'name' => $this->translate('installer.requirement.project_writable', [], 'Writable project configuration'),
            'passed' => is_writable($rootPath),
            'detail' => $this->translate('installer.requirement.project_writable_help', [], 'Required to create .env during installation'),
        ];

        $checks[] = [
            'name' => $this->translate('installer.requirement.no_env', [], 'No existing environment file'),
            'passed' => ! file_exists($rootPath . '/.env') && ! is_link($rootPath . '/.env'),
            'detail' => (file_exists($rootPath . '/.env') || is_link($rootPath . '/.env'))
                ? $this->translate('installer.requirement.env_exists', [], 'Review and remove the existing .env manually')
                : $this->translate('installer.requirement.env_ready', [], 'Ready to create .env'),
        ];

        $checks[] = [
            'name' => $this->translate('installer.requirement.no_lock', [], 'No installation lock'),
            'passed' => ! file_exists($rootPath . '/storage/installed.lock') && ! is_link($rootPath . '/storage/installed.lock'),
            'detail' => (file_exists($rootPath . '/storage/installed.lock') || is_link($rootPath . '/storage/installed.lock'))
                ? $this->translate('installer.error.already_installed', [], 'NovaNuke is already installed')
                : $this->translate('installer.requirement.unlocked', [], 'Installer is unlocked'),
        ];

        return $checks;
    }

    /** @param list<array{name: string, passed: bool, detail: string}> $checks */
    public function allPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! $check['passed']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, scalar|null> $parameters */
    private function translate(string $key, array $parameters, string $fallback): string
    {
        return $this->translator?->translate($key, $parameters) ?? $fallback;
    }
}
