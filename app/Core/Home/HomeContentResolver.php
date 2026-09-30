<?php

declare(strict_types=1);

namespace NovaNuke\Core\Home;

use JsonException;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Settings\SettingsRepository;
use RuntimeException;

final class HomeContentResolver
{
    /** @var array<string, array<string,string>> */
    private array $defaults = [];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Translator $translator,
        private readonly string $languageDirectory,
    ) {
    }

    /** @return array{headline:string,description:string,description_custom:bool} */
    public function resolve(?string $username = null): array
    {
        return $this->resolveFor($this->translator->locale(), $username);
    }

    /** @return array{headline:string,description:string,description_custom:bool} */
    public function resolveFor(string $locale, ?string $username = null): array
    {
        $defaults = $this->defaultsFor($locale);
        $headline = $this->custom($locale, 'headline');
        $description = $this->custom($locale, 'description');

        $accountTitle = $this->interpolate($this->customOrDefault($locale, 'card.account.title', $defaults), $username);
        return [
            'headline' => $headline === '' ? $defaults['headline'] : $headline,
            'description' => $description === '' ? $defaults['description'] : $description,
            'description_custom' => $description !== '',
            'cards' => [
                'explore' => [
                    'title' => $this->customOrDefault($locale, 'card.explore.title', $defaults),
                    'description' => $this->customOrDefault($locale, 'card.explore.description', $defaults),
                ],
                'account' => [
                    'title' => $accountTitle,
                    'title_template' => $this->customOrDefault($locale, 'card.account.title', $defaults),
                    'description' => $this->customOrDefault($locale, 'card.account.description', $defaults),
                ],
                'guest' => [
                    'title' => $this->customOrDefault($locale, 'card.guest.title', $defaults),
                    'description' => $this->customOrDefault($locale, 'card.guest.description', $defaults),
                ],
            ],
        ];
    }

    /** @return array{home_en_headline:string,home_en_description:string,home_es_headline:string,home_es_description:string} */
    public function formValues(): array
    {
        return [
            'home_en_headline' => $this->formValue('en', 'headline'),
            'home_en_description' => $this->formValue('en', 'description'),
            'home_es_headline' => $this->formValue('es', 'headline'),
            'home_es_description' => $this->formValue('es', 'description'),
            'home_en_card_explore_title' => $this->formValue('en', 'card.explore.title'),
            'home_en_card_explore_description' => $this->formValue('en', 'card.explore.description'),
            'home_en_card_account_title' => $this->formValue('en', 'card.account.title'),
            'home_en_card_account_description' => $this->formValue('en', 'card.account.description'),
            'home_en_card_guest_title' => $this->formValue('en', 'card.guest.title'),
            'home_en_card_guest_description' => $this->formValue('en', 'card.guest.description'),
            'home_es_card_explore_title' => $this->formValue('es', 'card.explore.title'),
            'home_es_card_explore_description' => $this->formValue('es', 'card.explore.description'),
            'home_es_card_account_title' => $this->formValue('es', 'card.account.title'),
            'home_es_card_account_description' => $this->formValue('es', 'card.account.description'),
            'home_es_card_guest_title' => $this->formValue('es', 'card.guest.title'),
            'home_es_card_guest_description' => $this->formValue('es', 'card.guest.description'),
        ];
    }

    private function formValue(string $locale, string $field): string
    {
        $custom = $this->custom($locale, $field);
        return $custom === '' ? $this->defaultsFor($locale)[$field] : $custom;
    }

    private function custom(string $locale, string $field): string
    {
        return trim($this->settings->string("home.content.{$locale}.{$field}", ''));
    }

    /** @return array{headline:string,description:string} */
    private function defaultsFor(string $locale): array
    {
        $locale = in_array($locale, ['en', 'es'], true) ? $locale : 'en';
        if (isset($this->defaults[$locale])) return $this->defaults[$locale];

        $path = rtrim($this->languageDirectory, '/\\') . DIRECTORY_SEPARATOR . $locale . '.json';
        try {
            $catalogue = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("Invalid core translation catalogue: {$path}", 0, $error);
        }
        if (! is_array($catalogue)) throw new RuntimeException("Core translation catalogue must contain an object: {$path}");

        return $this->defaults[$locale] = [
            'headline' => (string) ($catalogue['home.headline'] ?? ''),
            'description' => (string) ($catalogue['home.description'] ?? ''),
            'card.explore.title' => (string) ($catalogue['home.card.explore.title'] ?? ''),
            'card.explore.description' => (string) ($catalogue['home.card.explore.description'] ?? ''),
            'card.account.title' => (string) ($catalogue['home.card.account.title'] ?? ''),
            'card.account.description' => (string) ($catalogue['home.card.account.description'] ?? ''),
            'card.guest.title' => (string) ($catalogue['home.card.guest.title'] ?? ''),
            'card.guest.description' => (string) ($catalogue['home.card.guest.description'] ?? ''),
        ];
    }

    private function customOrDefault(string $locale, string $field, array $defaults): string
    {
        $custom = $this->custom($locale, $field);
        return $custom === '' ? $defaults[$field] : $custom;
    }

    private function interpolate(string $template, ?string $username): string
    {
        return $username === null ? $template : str_replace('{username}', $username, $template);
    }
}
