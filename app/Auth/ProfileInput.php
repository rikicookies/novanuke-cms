<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\I18n\LocaleRegistry;
use NovaNuke\Core\Content\ContentFormat;

final class ProfileInput
{
    public function __construct(private readonly ?LocaleRegistry $locales = null) {}
    /** @param array<string,mixed> $input @return array{data:array<string,mixed>,errors:array<string,string>} */
    public function validate(array $input): array
    {
        $displayName = trim($this->text($input['display_name'] ?? ''));
        $bio = trim($this->text($input['bio'] ?? ''));
        $bioFormat = null;
        $website = trim($this->text($input['website'] ?? ''));
        $location = trim($this->text($input['location'] ?? ''));
        $locale = $this->text($input['locale'] ?? '');
        $timezone = $this->text($input['timezone'] ?? '');
        $visibility = $this->text($input['profile_visibility'] ?? 'public');
        $errors = [];

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $displayName)) {
            $errors['display_name'] = 'Display name must contain between 2 and 100 characters.';
        }
        if (mb_strlen($bio) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bio)) {
            $errors['bio'] = 'Biography cannot exceed 2,000 characters.';
        }
        try { $bioFormat = ContentFormat::fromInput($input['bio_format'] ?? null, ContentFormat::Markdown); }
        catch (\RuntimeException) { $errors['bio_format'] = 'Select a valid biography format.'; }
        $websiteParts = $website === '' ? [] : parse_url($website);
        if ($website !== '' && (mb_strlen($website) > 255 || filter_var($website, FILTER_VALIDATE_URL) === false
            || ! is_array($websiteParts) || ! in_array(strtolower((string) ($websiteParts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($websiteParts['user']) || isset($websiteParts['pass']))) $errors['website'] = 'Enter a valid HTTP or HTTPS website URL.';
        if (mb_strlen($location) > 120 || preg_match('/[\x00-\x1F\x7F]/', $location)) $errors['location'] = 'Location cannot exceed 120 characters.';
        if (! ($this->locales?->supports($locale) ?? in_array($locale, ['en', 'es'], true))) $errors['locale'] = 'Select an available language.';
        if (! in_array($timezone, timezone_identifiers_list(), true)) $errors['timezone'] = 'Select a valid timezone.';
        if (! in_array($visibility, ['public', 'members'], true)) $errors['profile_visibility'] = 'Select a valid profile visibility.';

        return ['data' => [
            'display_name' => $displayName,
            'bio' => $bio,
            'bio_format' => ($bioFormat ?? ContentFormat::Markdown)->value,
            'website' => $website,
            'location' => $location,
            'locale' => $locale,
            'timezone' => $timezone,
            'profile_visibility' => $visibility,
            'preferences' => ['profile_visibility' => $visibility],
        ], 'errors' => $errors];
    }

    private function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
