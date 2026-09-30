<?php

declare(strict_types=1);

namespace NovaNuke\Core\Themes;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;
use NovaNuke\Core\I18n\Translator;

final class ThemeManager
{
    public function __construct(
        private readonly ThemeDetector $detector,
        private readonly ThemeRepository $repository,
        private readonly ThemeAssetPublisher $assets,
        private readonly SettingsRepository $settings,
        private readonly ViewRenderer $views,
        private readonly EventDispatcher $events,
        private readonly Translator $translator,
        private readonly string $cmsVersion,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function inventory(): array
    {
        $detected = $this->detector->detect();
        $installed = $this->repository->all();
        $active = $this->activeSlug();
        $inventory = [];
        foreach ($detected as $slug => $manifest) {
            $record = $installed[$slug] ?? null;
            $compatible = version_compare($this->cmsVersion, $manifest->cmsMinVersion, '>=');
            $inventory[$slug] = [
                'manifest' => $manifest,
                'installed' => $record !== null,
                'installed_version' => $record['installed_version'] ?? null,
                'settings' => $record !== null
                    ? array_replace($this->defaults($manifest), $record['settings'])
                    : $this->defaults($manifest),
                'active' => $active === $slug,
                'compatible' => $compatible,
                'compatibility_reason' => $compatible ? null : "Requires NovaNuke {$manifest->cmsMinVersion} or newer.",
                'update_available' => $record !== null
                    && version_compare($manifest->version, (string) $record['installed_version'], '>'),
                'missing_files' => false,
            ];
        }
        foreach ($installed as $slug => $record) {
            if (! isset($inventory[$slug])) {
                $inventory[$slug] = [
                    'manifest' => null,
                    'installed' => true,
                    'installed_version' => $record['installed_version'],
                    'settings' => $record['settings'],
                    'active' => $active === $slug,
                    'compatible' => false,
                    'compatibility_reason' => 'Theme files are missing from disk.',
                    'update_available' => false,
                    'missing_files' => true,
                    'name' => $record['name'],
                ];
            }
        }
        ksort($inventory);

        return $inventory;
    }

    public function install(string $slug): void
    {
        $manifest = $this->manifest($slug);
        if (isset($this->repository->all()[$slug])) {
            throw new RuntimeException('The theme is already installed.');
        }
        $this->assertCompatible($manifest);
        $this->assets->publish($manifest);
        $this->repository->install($manifest, $this->defaults($manifest));
    }

    public function update(string $slug): void
    {
        $manifest = $this->manifest($slug);
        $record = $this->repository->all()[$slug] ?? null;
        if ($record === null) {
            throw new RuntimeException('The theme is not installed.');
        }
        if (version_compare($manifest->version, (string) $record['installed_version'], '<=')) {
            throw new RuntimeException('No newer theme version is available.');
        }
        $this->assertCompatible($manifest);
        $this->assets->publish($manifest);
        $this->repository->install($manifest, $record['settings']);
        $this->repository->saveSettings(
            $slug,
            array_replace($this->defaults($manifest), $record['settings']),
        );
    }

    public function activate(string $slug): void
    {
        $manifest = $this->manifest($slug);
        if (! isset($this->repository->all()[$slug])) {
            throw new RuntimeException('Install the theme before activating it.');
        }
        $this->assertCompatible($manifest);
        $this->assets->publish($manifest);
        $this->settings->setString('theme.active', $slug, 'appearance');
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::THEME_ACTIVATED, new ThemeActivated($slug));
    }

    public function uninstall(string $slug): void
    {
        if ($this->activeSlug() === $slug) {
            throw new RuntimeException('Activate another theme before uninstalling this one.');
        }
        if (! isset($this->repository->all()[$slug])) {
            throw new RuntimeException('The theme is not installed.');
        }
        $this->assets->remove($slug);
        $this->repository->remove($slug);
    }

    /** @param array<string, mixed> $input */
    public function configure(string $slug, array $input): void
    {
        $manifest = $this->manifest($slug);
        if (! isset($this->repository->all()[$slug])) {
            throw new RuntimeException('The theme is not installed.');
        }
        $this->repository->saveSettings($slug, $this->normalizeSettings($manifest, $input));
    }

    public function bootActive(): void
    {
        if (! $this->repository->available()) {
            return;
        }
        $slug = $this->activeSlug();
        if ($slug === '') {
            return;
        }
        $detected = $this->detector->detect();
        $installed = $this->repository->all();
        $manifest = $detected[$slug] ?? null;
        $record = $installed[$slug] ?? null;
        if ($manifest === null || $record === null) {
            $fallback = $detected['novamodern'] ?? null;
            $fallbackRecord = $installed['novamodern'] ?? null;
            if ($fallback === null || $fallbackRecord === null) {
                return;
            }
            $this->settings->setString('theme.active', 'novamodern', 'appearance');
            $manifest = $fallback;
            $record = $fallbackRecord;
        }

        $this->translator->addNamespace('theme', $manifest->path . '/language');
        $this->views->prependPath($manifest->path);
        if (is_dir($manifest->path . '/templates')) {
            $this->views->prependPath($manifest->path . '/templates');
        }
        $overrideRoot = $manifest->path . '/module-templates';
        foreach (glob($overrideRoot . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $namespace = strtolower(basename($directory));
            // Module view namespaces use the same restricted identifier format as
            // ViewRenderer. Ignore unrelated/invalid folders instead of allowing a
            // malformed theme package to break the public site during theme boot.
            if (! preg_match('/^[a-z][a-z0-9_-]*$/', $namespace)) {
                continue;
            }
            // `admin-*` namespaces are reserved for module-owned backend views.
            // Themes may replace public module presentation, never Admin markup.
            if (str_starts_with($namespace, 'admin-')) {
                continue;
            }
            $this->views->prependNamespace($namespace, $directory);
        }
        $this->views->addGlobal('theme', [
            'slug' => $manifest->slug,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'asset_base' => '/assets/themes/' . $manifest->slug,
            'settings' => array_replace($this->defaults($manifest), $record['settings']),
            'positions' => $manifest->positions,
            'layouts' => $manifest->layouts,
        ]);
    }

    public function activeSlug(): string
    {
        return $this->settings->string('theme.active', '');
    }

    private function manifest(string $slug): ThemeManifest
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $slug)) {
            throw new RuntimeException('Invalid theme slug.');
        }
        $manifest = $this->detector->detect()[$slug] ?? null;
        if ($manifest === null) {
            throw new RuntimeException('Theme files were not found.');
        }

        return $manifest;
    }

    private function assertCompatible(ThemeManifest $manifest): void
    {
        if (version_compare($this->cmsVersion, $manifest->cmsMinVersion, '<')) {
            throw new RuntimeException("Requires NovaNuke {$manifest->cmsMinVersion} or newer.");
        }
    }

    /** @return array<string, mixed> */
    private function defaults(ThemeManifest $manifest): array
    {
        $defaults = [];
        foreach ($manifest->settings as $key => $definition) {
            $defaults[$key] = $this->normalizeValue($key, $definition, $definition['default'] ?? null);
        }

        return $defaults;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private function normalizeSettings(ThemeManifest $manifest, array $input): array
    {
        $settings = [];
        foreach ($manifest->settings as $key => $definition) {
            $settings[$key] = $this->normalizeValue($key, $definition, $input[$key] ?? null);
        }

        return $settings;
    }

    /** @param array<string, mixed> $definition */
    private function normalizeValue(string $key, array $definition, mixed $value): mixed
    {
        return match ($definition['type']) {
            'boolean' => $value === true || $value === '1' || $value === 1,
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)
                ? strtolower($value)
                : throw new RuntimeException("Invalid color setting: {$key}"),
            'text' => is_string($value) && mb_strlen(trim($value)) <= 200
                ? trim($value)
                : throw new RuntimeException("Invalid text setting: {$key}"),
            default => throw new RuntimeException("Unsupported theme setting: {$key}"),
        };
    }
}
