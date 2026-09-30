<?php

declare(strict_types=1);

namespace Modules\Welcome\src;

use JsonException;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Settings\SettingsRepository;
use RuntimeException;

final class WelcomeContentResolver
{
    /** @var array<string,string> */
    private const URLS = [
        'news' => '/news',
        'downloads' => '/downloads',
        'docs' => '/wiki',
        'resources' => '/links',
    ];

    /** @var array<string, array{section:string,label:string,max:int,textarea:bool}> */
    private const FIELDS = [
        'hero.eyebrow' => ['section' => 'hero', 'label' => 'welcome::admin.field.hero.eyebrow', 'max' => 200, 'textarea' => false],
        'hero.title' => ['section' => 'hero', 'label' => 'welcome::admin.field.hero.title', 'max' => 200, 'textarea' => false],
        'hero.description' => ['section' => 'hero', 'label' => 'welcome::admin.field.hero.description', 'max' => 1000, 'textarea' => true],
        'story.modern' => ['section' => 'story', 'label' => 'welcome::admin.field.story.modern', 'max' => 5000, 'textarea' => true],
        'story.roots' => ['section' => 'story', 'label' => 'welcome::admin.field.story.roots', 'max' => 5000, 'textarea' => true],
        'story.spirit' => ['section' => 'story', 'label' => 'welcome::admin.field.story.spirit', 'max' => 5000, 'textarea' => true],
        'feature.core.title' => ['section' => 'features', 'label' => 'welcome::admin.field.feature.core.title', 'max' => 200, 'textarea' => false],
        'feature.core.text' => ['section' => 'features', 'label' => 'welcome::admin.field.feature.core.text', 'max' => 2000, 'textarea' => true],
        'feature.web.title' => ['section' => 'features', 'label' => 'welcome::admin.field.feature.web.title', 'max' => 200, 'textarea' => false],
        'feature.web.text' => ['section' => 'features', 'label' => 'welcome::admin.field.feature.web.text', 'max' => 2000, 'textarea' => true],
        'feature.pieces.title' => ['section' => 'features', 'label' => 'welcome::admin.field.feature.pieces.title', 'max' => 200, 'textarea' => false],
        'feature.pieces.text' => ['section' => 'features', 'label' => 'welcome::admin.field.feature.pieces.text', 'max' => 2000, 'textarea' => true],
        'capabilities.eyebrow' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.eyebrow', 'max' => 200, 'textarea' => false],
        'capabilities.title' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.title', 'max' => 200, 'textarea' => false],
        'capabilities.description' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.description', 'max' => 2000, 'textarea' => true],
        'capabilities.news' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.news', 'max' => 200, 'textarea' => false],
        'capabilities.downloads' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.downloads', 'max' => 200, 'textarea' => false],
        'capabilities.wiki' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.wiki', 'max' => 200, 'textarea' => false],
        'capabilities.users' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.users', 'max' => 200, 'textarea' => false],
        'capabilities.audiences' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.audiences', 'max' => 200, 'textarea' => false],
        'capabilities.extensions' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.extensions', 'max' => 200, 'textarea' => false],
        'capabilities.search' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.search', 'max' => 200, 'textarea' => false],
        'capabilities.operations' => ['section' => 'capabilities', 'label' => 'welcome::admin.field.capabilities.operations', 'max' => 200, 'textarea' => false],
        'status.eyebrow' => ['section' => 'status', 'label' => 'welcome::admin.field.status.eyebrow', 'max' => 200, 'textarea' => false],
        'status.title' => ['section' => 'status', 'label' => 'welcome::admin.field.status.title', 'max' => 200, 'textarea' => false],
        'status.text' => ['section' => 'status', 'label' => 'welcome::admin.field.status.text', 'max' => 3000, 'textarea' => true],
        'explore.eyebrow' => ['section' => 'explore', 'label' => 'welcome::admin.field.explore.eyebrow', 'max' => 200, 'textarea' => false],
        'explore.title' => ['section' => 'explore', 'label' => 'welcome::admin.field.explore.title', 'max' => 200, 'textarea' => false],
        'explore.description' => ['section' => 'explore', 'label' => 'welcome::admin.field.explore.description', 'max' => 3000, 'textarea' => true],
        'link.news.label' => ['section' => 'links', 'label' => 'welcome::admin.field.link.news.label', 'max' => 200, 'textarea' => false],
        'link.news.help' => ['section' => 'links', 'label' => 'welcome::admin.field.link.news.help', 'max' => 500, 'textarea' => true],
        'link.downloads.label' => ['section' => 'links', 'label' => 'welcome::admin.field.link.downloads.label', 'max' => 200, 'textarea' => false],
        'link.downloads.help' => ['section' => 'links', 'label' => 'welcome::admin.field.link.downloads.help', 'max' => 500, 'textarea' => true],
        'link.docs.label' => ['section' => 'links', 'label' => 'welcome::admin.field.link.docs.label', 'max' => 200, 'textarea' => false],
        'link.docs.help' => ['section' => 'links', 'label' => 'welcome::admin.field.link.docs.help', 'max' => 500, 'textarea' => true],
        'link.resources.label' => ['section' => 'links', 'label' => 'welcome::admin.field.link.resources.label', 'max' => 200, 'textarea' => false],
        'link.resources.help' => ['section' => 'links', 'label' => 'welcome::admin.field.link.resources.help', 'max' => 500, 'textarea' => true],
        'closing.lead' => ['section' => 'closing', 'label' => 'welcome::admin.field.closing.lead', 'max' => 1000, 'textarea' => true],
        'closing.title' => ['section' => 'closing', 'label' => 'welcome::admin.field.closing.title', 'max' => 300, 'textarea' => false],
        'closing.welcome' => ['section' => 'closing', 'label' => 'welcome::admin.field.closing.welcome', 'max' => 500, 'textarea' => false],
    ];

    /** @var array<string, array<string,string>> */
    private array $defaults = [];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Translator $translator,
        private readonly string $languageDirectory,
    ) {
    }

    /** @return array<string, array{section:string,label:string,max:int,textarea:bool}> */
    public function fields(): array
    {
        return self::FIELDS;
    }

    /** @return array<string,mixed> */
    public function resolve(): array
    {
        return $this->resolveFor($this->translator->locale());
    }

    /** @return array<string,mixed> */
    public function resolveFor(string $locale): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $values[$field] = $this->value($locale, $field);
        }

        return [
            'hero' => [
                'eyebrow' => $values['hero.eyebrow'], 'title' => $values['hero.title'], 'description' => $values['hero.description'],
            ],
            'story' => [
                'modern' => $values['story.modern'], 'roots' => $values['story.roots'], 'spirit' => $values['story.spirit'],
            ],
            'feature' => [
                'core' => ['title' => $values['feature.core.title'], 'text' => $values['feature.core.text']],
                'web' => ['title' => $values['feature.web.title'], 'text' => $values['feature.web.text']],
                'pieces' => ['title' => $values['feature.pieces.title'], 'text' => $values['feature.pieces.text']],
            ],
            'capabilities' => [
                'eyebrow' => $values['capabilities.eyebrow'], 'title' => $values['capabilities.title'], 'description' => $values['capabilities.description'],
                'news' => $values['capabilities.news'], 'downloads' => $values['capabilities.downloads'], 'wiki' => $values['capabilities.wiki'],
                'users' => $values['capabilities.users'], 'audiences' => $values['capabilities.audiences'], 'extensions' => $values['capabilities.extensions'],
                'search' => $values['capabilities.search'], 'operations' => $values['capabilities.operations'],
            ],
            'status' => ['eyebrow' => $values['status.eyebrow'], 'title' => $values['status.title'], 'text' => $values['status.text']],
            'explore' => ['eyebrow' => $values['explore.eyebrow'], 'title' => $values['explore.title'], 'description' => $values['explore.description']],
            'links' => [
                'news' => ['label' => $values['link.news.label'], 'help' => $values['link.news.help'], 'url' => $this->url('news')],
                'downloads' => ['label' => $values['link.downloads.label'], 'help' => $values['link.downloads.help'], 'url' => $this->url('downloads')],
                'docs' => ['label' => $values['link.docs.label'], 'help' => $values['link.docs.help'], 'url' => $this->url('docs')],
                'resources' => ['label' => $values['link.resources.label'], 'help' => $values['link.resources.help'], 'url' => $this->url('resources')],
            ],
            'closing' => ['lead' => $values['closing.lead'], 'title' => $values['closing.title'], 'welcome' => $values['closing.welcome']],
        ];
    }

    /** @return array<string,string> */
    public function formValues(): array
    {
        $result = [];
        foreach (['en', 'es'] as $locale) {
            foreach (array_keys(self::FIELDS) as $field) {
                $result[$this->inputName($locale, $field)] = $this->value($locale, $field);
            }
        }
        foreach (self::URLS as $key => $_default) $result['welcome_link_' . $key . '_url'] = $this->url($key);
        return $result;
    }

    /** @return array{data:array<string,string>,errors:array<string,string>} */
    public function validate(array $input): array
    {
        $data = [];
        $errors = [];
        foreach (['en', 'es'] as $locale) {
            foreach (self::FIELDS as $field => $definition) {
                $name = $this->inputName($locale, $field);
                $value = trim((string) ($input[$name] ?? ''));
                $data[$name] = $value;
                if (mb_strlen($value) > $definition['max'] || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    $errors[$name] = 'welcome::admin.error.field_length';
                }
            }
        }
        foreach (self::URLS as $key => $_default) {
            $name = 'welcome_link_' . $key . '_url';
            $value = trim((string) ($input[$name] ?? ''));
            $data[$name] = $value;
            if ($value !== '' && ! $this->validUrl($value)) $errors[$name] = 'welcome::admin.error.url';
        }
        return ['data' => $data, 'errors' => $errors];
    }

    /** @return array<string, array<string, array{label:string,max:int,textarea:bool,name:string,shared?:bool}>> */
    public function sections(): array
    {
        $sections = [];
        foreach (self::FIELDS as $field => $definition) {
            $sections[$definition['section']][] = [
                'label' => $definition['label'], 'max' => $definition['max'], 'textarea' => $definition['textarea'],
                'name' => $field,
            ];
        }
        foreach (self::URLS as $key => $_default) {
            $sections['links'][] = [
                'label' => 'welcome::admin.field.link.' . $key . '.url',
                'max' => 2048,
                'textarea' => false,
                'name' => 'link.' . $key . '.url',
                'shared' => true,
            ];
        }
        return $sections;
    }

    private function inputName(string $locale, string $field): string
    {
        return 'welcome_' . $locale . '_' . str_replace('.', '_', $field);
    }

    private function value(string $locale, string $field): string
    {
        $locale = in_array($locale, ['en', 'es'], true) ? $locale : 'en';
        $custom = trim($this->settings->string("welcome.content.{$locale}.{$field}", ''));
        return $custom === '' ? ($this->defaultsFor($locale)[$field] ?? '') : $custom;
    }

    /** @return array<string,string> */
    public function urlDefaults(): array
    {
        return self::URLS;
    }

    private function url(string $key): string
    {
        $custom = trim($this->settings->string('welcome.link.' . $key . '.url', ''));
        return $custom === '' ? self::URLS[$key] : $custom;
    }

    private function validUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || preg_match('/%(?![0-9A-Fa-f]{2})/', $url)) return false;
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return parse_url($url, PHP_URL_PATH) !== false;
        }
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /** @return array<string,string> */
    private function defaultsFor(string $locale): array
    {
        if (isset($this->defaults[$locale])) return $this->defaults[$locale];
        $path = rtrim($this->languageDirectory, '/\\') . DIRECTORY_SEPARATOR . $locale . '.json';
        try {
            $catalogue = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("Invalid Welcome translation catalogue: {$path}", 0, $error);
        }
        if (! is_array($catalogue)) throw new RuntimeException("Welcome translation catalogue must contain an object: {$path}");
        $defaults = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $translationKey = $field;
            if (str_starts_with($field, 'link.')) {
                $parts = explode('.', $field);
                $translationKey = 'link.' . $parts[1] . ($parts[2] === 'help' ? '_help' : '');
            }
            $defaults[$field] = (string) ($catalogue[$translationKey] ?? '');
        }
        return $this->defaults[$locale] = $defaults;
    }
}
