<?php

declare(strict_types=1);

namespace NovaNuke\Core\Themes;

use PDO;

final class ThemeRepository
{
    private ?bool $availableCache=null;
    /** @var array<string,array<string,mixed>>|null */ private ?array $allCache=null;
    public function __construct(private readonly PDO $database)
    {
    }

    public function available(): bool
    {
        if($this->availableCache!==null)return$this->availableCache;
        return$this->availableCache=$this->database->query("SHOW TABLES LIKE 'themes'")->fetchColumn() !== false;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if($this->allCache!==null)return$this->allCache;
        if (! $this->available()) {
            return [];
        }
        $rows = $this->database->query(
            'SELECT slug, name, installed_version, manifest, settings, installed_at, updated_at FROM themes'
        )->fetchAll();
        $themes = [];
        foreach ($rows as $row) {
            $row['settings'] = is_string($row['settings'])
                ? (json_decode($row['settings'], true) ?: [])
                : [];
            $themes[(string) $row['slug']] = $row;
        }

        return $this->allCache=$themes;
    }

    public function install(ThemeManifest $manifest, array $settings): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO themes (slug, name, installed_version, manifest, settings, installed_at, updated_at) '
            . 'VALUES (:slug, :name, :version, :manifest, :settings, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), installed_version = VALUES(installed_version), '
            . 'manifest = VALUES(manifest), updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'slug' => $manifest->slug,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'manifest' => json_encode($manifest->toArray(), JSON_THROW_ON_ERROR),
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
        $this->allCache=null;
    }

    public function saveSettings(string $slug, array $settings): void
    {
        $statement = $this->database->prepare(
            'UPDATE themes SET settings = :settings, updated_at = UTC_TIMESTAMP() WHERE slug = :slug'
        );
        $statement->execute(['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'slug' => $slug]);
        $this->allCache=null;
    }

    public function remove(string $slug): void
    {
        $statement = $this->database->prepare('DELETE FROM themes WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        $this->allCache=null;
    }
}
