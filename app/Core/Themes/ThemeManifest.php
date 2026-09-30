<?php

declare(strict_types=1);

namespace NovaNuke\Core\Themes;

use InvalidArgumentException;

final readonly class ThemeManifest
{
    /** @param list<string> $layouts
     *  @param list<string> $positions
     *  @param array<string, array<string, mixed>> $settings
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $version,
        public string $description,
        public string $author,
        public string $cmsMinVersion,
        public string $screenshot,
        public array $layouts,
        public array $positions,
        public array $settings,
        public string $path,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path): self
    {
        foreach (['name', 'slug', 'version', 'cms_min_version'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || trim($data[$field]) === '') {
                throw new InvalidArgumentException("Theme manifest field is required: {$field}");
            }
        }
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $data['slug'])) {
            throw new InvalidArgumentException('Theme slug must use lowercase letters, numbers and hyphens.');
        }
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/', $data['version'])) {
            throw new InvalidArgumentException('Theme version must use semantic versioning.');
        }
        $layouts = $data['layouts'] ?? ['default'];
        $positions = $data['positions'] ?? [];
        $settings = $data['settings'] ?? [];
        if (! is_array($layouts) || ! is_array($positions) || ! is_array($settings)) {
            throw new InvalidArgumentException('Theme layouts, positions and settings must be arrays.');
        }
        foreach ($layouts as $layout) {
            if (! is_string($layout) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $layout)) {
                throw new InvalidArgumentException('Invalid theme layout name.');
            }
        }
        foreach ($positions as $position) {
            if (! is_string($position) || ! preg_match('/^[a-z][a-z0-9-]*$/', $position)) {
                throw new InvalidArgumentException('Invalid block position name.');
            }
        }
        foreach ($settings as $key => $definition) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]*$/', $key) || ! is_array($definition)) {
                throw new InvalidArgumentException('Invalid theme setting definition.');
            }
            if (! in_array($definition['type'] ?? null, ['text', 'boolean', 'color'], true)) {
                throw new InvalidArgumentException("Unsupported theme setting type: {$key}");
            }
        }
        $screenshot = (string) ($data['screenshot'] ?? '');
        if ($screenshot !== '' && basename($screenshot) !== $screenshot) {
            throw new InvalidArgumentException('Theme screenshot must be a filename in the theme root.');
        }

        return new self(
            trim($data['name']),
            $data['slug'],
            $data['version'],
            (string) ($data['description'] ?? ''),
            (string) ($data['author'] ?? ''),
            $data['cms_min_version'],
            $screenshot,
            array_values(array_map('strval', $layouts)),
            array_values(array_map('strval', $positions)),
            $settings,
            $path,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'cms_min_version' => $this->cmsMinVersion,
            'screenshot' => $this->screenshot,
            'layouts' => $this->layouts,
            'positions' => $this->positions,
            'settings' => $this->settings,
        ];
    }
}
