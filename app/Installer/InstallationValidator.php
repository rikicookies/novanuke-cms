<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use NovaNuke\Core\I18n\LocaleRegistry;
use NovaNuke\Core\I18n\Translator;

final class InstallationValidator
{
    public function __construct(
        private readonly ?LocaleRegistry $locales = null,
        private readonly ?Translator $translator = null,
    ) {}
    /** @return array<string,string> */ public function availableLocales():array{return$this->locales?->all()??['en'=>'English','es'=>'Español'];}
    /** @param array<string, mixed> $input
     *  @return array<string, string>
     */
    public function validate(array $input): array
    {
        $errors = [];

        $this->requiredLength($errors, $input, 'site_name', 2, 100);
        $this->requiredLength($errors, $input, 'admin_username', 3, 32);
        $this->requiredLength($errors, $input, 'database_host', 1, 255);
        $this->requiredLength($errors, $input, 'database_username', 1, 128);

        $siteUrl = trim((string) ($input['site_url'] ?? ''));
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        if (! filter_var($siteUrl, FILTER_VALIDATE_URL)
            || ! in_array($scheme, ['http', 'https'], true)
            || parse_url($siteUrl, PHP_URL_USER) !== null
            || parse_url($siteUrl, PHP_URL_PASS) !== null
            || parse_url($siteUrl, PHP_URL_QUERY) !== null
            || parse_url($siteUrl, PHP_URL_FRAGMENT) !== null
            || preg_match('/[\x00-\x1F\x7F]/', $siteUrl)) {
            $errors['site_url'] = $this->translate('installer.validation.site_url', [], 'Enter a valid site URL including http:// or https://.');
        }

        $databaseHost = trim((string) ($input['database_host'] ?? ''));
        if (! preg_match('/^[a-zA-Z0-9._:-]{1,255}$/', $databaseHost)) {
            $errors['database_host'] = $this->translate('installer.validation.database_host', [], 'Enter a hostname or IP address without connection options.');
        }

        if (! ($this->locales?->supports((string)($input['locale']??'')) ?? in_array($input['locale'] ?? null, ['en', 'es'], true))) {
            $errors['locale'] = $this->translate('installer.validation.locale', [], 'Select an available language.');
        }

        if (! in_array($input['timezone'] ?? null, timezone_identifiers_list(), true)) {
            $errors['timezone'] = $this->translate('installer.validation.timezone', [], 'Select a valid PHP timezone.');
        }

        if (! preg_match('/^[a-zA-Z0-9_]{1,64}$/', (string) ($input['database_name'] ?? ''))) {
            $errors['database_name'] = $this->translate('installer.validation.database_name', [], 'Use only letters, numbers and underscores for the database name.');
        }

        $port = filter_var($input['database_port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            $errors['database_port'] = $this->translate('installer.validation.database_port', [], 'Enter a valid database port.');
        }

        if (! preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', (string) ($input['admin_username'] ?? ''))) {
            $errors['admin_username'] = $this->translate('installer.validation.admin_username', [], 'Use 3-32 letters, numbers, dots, underscores or hyphens.');
        }

        if (! filter_var($input['admin_email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = $this->translate('installer.validation.admin_email', [], 'Enter a valid administrator email.');
        }

        $password = (string) ($input['admin_password'] ?? '');
        if (strlen($password) < 12 || strlen($password) > 255) {
            $errors['admin_password'] = $this->translate('installer.validation.admin_password', [], 'Use a password between 12 and 255 characters.');
        }
        if (! hash_equals($password, (string) ($input['admin_password_confirmation'] ?? ''))) {
            $errors['admin_password_confirmation'] = $this->translate('installer.validation.password_confirmation', [], 'The passwords do not match.');
        }

        return $errors;
    }

    /** @param array<string, string> $errors
     *  @param array<string, mixed> $input
     */
    private function requiredLength(array &$errors, array $input, string $key, int $min, int $max): void
    {
        $value = trim((string) ($input[$key] ?? ''));
        $length = mb_strlen($value);

        if ($length < $min || $length > $max) {
            $errors[$key] = $this->translate('installer.validation.length', ['min' => $min, 'max' => $max], "This field must contain {$min}-{$max} characters.");
        }
    }

    /** @param array<string, scalar|null> $parameters */
    private function translate(string $key, array $parameters, string $fallback): string
    {
        return $this->translator?->translate($key, $parameters) ?? $fallback;
    }
}
