<?php

declare(strict_types=1);

namespace NovaNuke\Core\Settings;

use NovaNuke\Core\I18n\LocaleRegistry;

final class GeneralSettingsInput
{
    public function __construct(private readonly ?LocaleRegistry $locales = null) {}
    /** @var array<string, string> */
    public const DATE_FORMATS = [
        'Y-m-d' => '2026-09-02',
        'm/d/Y' => '09/02/2026',
        'd/m/Y' => '02/09/2026',
        'F j, Y' => 'September 2, 2026',
    ];

    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $homepages
     * @return array{data:array<string,mixed>,errors:array<string,string>}
     */
    public function validate(array $input, array $homepages): array
    {
        $data = [
            'name' => trim($this->scalarString($input['name'] ?? '')),
            'description' => trim(strip_tags($this->scalarString($input['description'] ?? ''))),
            'url' => rtrim(trim($this->scalarString($input['url'] ?? '')), '/'),
            'admin_email' => strtolower(trim($this->scalarString($input['admin_email'] ?? ''))),
            'timezone' => trim($this->scalarString($input['timezone'] ?? '')),
            'locale' => trim($this->scalarString($input['locale'] ?? '')),
            'date_format' => trim($this->scalarString($input['date_format'] ?? '')),
            'per_page' => filter_var($input['per_page'] ?? null, FILTER_VALIDATE_INT),
            'homepage' => trim($this->scalarString($input['homepage'] ?? '')),
            'maintenance' => ($input['maintenance'] ?? null) === '1',
            'home_en_headline' => trim($this->scalarString($input['home_en_headline'] ?? '')),
            'home_en_description' => trim($this->scalarString($input['home_en_description'] ?? '')),
            'home_es_headline' => trim($this->scalarString($input['home_es_headline'] ?? '')),
            'home_es_description' => trim($this->scalarString($input['home_es_description'] ?? '')),
            'home_en_card_explore_title' => trim($this->scalarString($input['home_en_card_explore_title'] ?? '')),
            'home_en_card_explore_description' => trim($this->scalarString($input['home_en_card_explore_description'] ?? '')),
            'home_en_card_account_title' => trim($this->scalarString($input['home_en_card_account_title'] ?? '')),
            'home_en_card_account_description' => trim($this->scalarString($input['home_en_card_account_description'] ?? '')),
            'home_en_card_guest_title' => trim($this->scalarString($input['home_en_card_guest_title'] ?? '')),
            'home_en_card_guest_description' => trim($this->scalarString($input['home_en_card_guest_description'] ?? '')),
            'home_es_card_explore_title' => trim($this->scalarString($input['home_es_card_explore_title'] ?? '')),
            'home_es_card_explore_description' => trim($this->scalarString($input['home_es_card_explore_description'] ?? '')),
            'home_es_card_account_title' => trim($this->scalarString($input['home_es_card_account_title'] ?? '')),
            'home_es_card_account_description' => trim($this->scalarString($input['home_es_card_account_description'] ?? '')),
            'home_es_card_guest_title' => trim($this->scalarString($input['home_es_card_guest_title'] ?? '')),
            'home_es_card_guest_description' => trim($this->scalarString($input['home_es_card_guest_description'] ?? '')),
        ];
        $errors = [];

        if (mb_strlen($data['name']) < 2 || mb_strlen($data['name']) > 100 || preg_match('/[\x00-\x1F\x7F]/', $data['name'])) {
            $errors['name'] = 'The site name must contain between 2 and 100 characters.';
        }
        if (mb_strlen($data['description']) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $data['description'])) {
            $errors['description'] = 'The site description cannot exceed 500 characters.';
        }
        if (! $this->validPublicUrl($data['url'])) {
            $errors['url'] = 'Enter a public HTTP or HTTPS URL without credentials, query parameters or fragments.';
        }
        if (strlen($data['admin_email']) > 254 || ! filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Enter a valid administrator email address.';
        }
        if (! in_array($data['timezone'], timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Select a valid PHP timezone.';
        }
        if (! ($this->locales?->supports($data['locale']) ?? in_array($data['locale'], ['en', 'es'], true))) {
            $errors['locale'] = 'Select an available language.';
        }
        if (! isset(self::DATE_FORMATS[$data['date_format']])) {
            $errors['date_format'] = 'Select an available date format.';
        }
        if ($data['per_page'] === false || $data['per_page'] < 5 || $data['per_page'] > 100) {
            $errors['per_page'] = 'Items per page must be between 5 and 100.';
        }
        if (! isset($homepages[$data['homepage']])) {
            $errors['homepage'] = 'Select an available homepage.';
        }
        foreach (['home_en_headline', 'home_es_headline'] as $field) {
            if (mb_strlen($data[$field]) > 200 || preg_match('/[\x00-\x1F\x7F]/', $data[$field])) {
                $errors[$field] = 'Home headlines must not exceed 200 characters.';
            }
        }
        foreach (['home_en_description', 'home_es_description'] as $field) {
            if (mb_strlen($data[$field]) > 500 || preg_match('/[\x00-\x1F\x7F]/', $data[$field])) {
                $errors[$field] = 'Home descriptions must not exceed 500 characters.';
            }
        }
        foreach (['home_en_card_explore_title', 'home_en_card_account_title', 'home_en_card_guest_title', 'home_es_card_explore_title', 'home_es_card_account_title', 'home_es_card_guest_title'] as $field) {
            if (mb_strlen($data[$field]) > 200 || preg_match('/[\x00-\x1F\x7F]/', $data[$field])) {
                $errors[$field] = 'Home card titles must not exceed 200 characters or contain control characters.';
            }
        }
        foreach (['home_en_card_explore_description', 'home_en_card_account_description', 'home_en_card_guest_description', 'home_es_card_explore_description', 'home_es_card_account_description', 'home_es_card_guest_description'] as $field) {
            if (mb_strlen($data[$field]) > 500 || preg_match('/[\x00-\x1F\x7F]/', $data[$field])) {
                $errors[$field] = 'Home card descriptions must not exceed 500 characters or contain control characters.';
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    private function validPublicUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private function scalarString(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
