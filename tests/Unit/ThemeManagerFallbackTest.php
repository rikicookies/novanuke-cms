<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\Themes\ThemeAssetPublisher;
use NovaNuke\Core\Themes\ThemeDetector;
use NovaNuke\Core\Themes\ThemeManager;
use NovaNuke\Core\Themes\ThemeRepository;
use NovaNuke\Core\View\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class ThemeManagerFallbackTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            if (! is_dir($root)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($root);
        }
    }

    public function testExistingValidActiveThemeRemainsUnchanged(): void
    {
        [$manager, $database] = $this->manager('novalearn', ['novalearn', 'novamodern'], ['novalearn', 'novamodern']);

        $manager->bootActive();

        self::assertSame('novalearn', $manager->activeSlug());
        self::assertSame('novalearn', $this->storedActiveTheme($database));
    }

    public function testMissingActiveThemeFallsBackToInstalledNovaModernAndPersistsIt(): void
    {
        [$manager, $database] = $this->manager('stickynova', ['novamodern'], ['novamodern']);

        $manager->bootActive();

        self::assertSame('novamodern', $manager->activeSlug());
        self::assertSame('novamodern', $this->storedActiveTheme($database));
    }

    public function testMissingActiveThemeDoesNotPersistUnavailableNovaModern(): void
    {
        [$manager, $database] = $this->manager('classic', [], []);

        $manager->bootActive();

        self::assertSame('classic', $manager->activeSlug());
        self::assertSame('classic', $this->storedActiveTheme($database));
    }

    /** @param list<string> $availableThemes @param list<string> $installedThemes */
    /** @return array{0:ThemeManager,1:ThemeTestDatabase} */
    private function manager(string $activeTheme, array $availableThemes, array $installedThemes): array
    {
        $root = sys_get_temp_dir() . '/novanuke-theme-fallback-' . bin2hex(random_bytes(6));
        $this->temporaryRoots[] = $root;
        mkdir($root . '/themes', 0770, true);
        mkdir($root . '/cache', 0770, true);
        foreach ($availableThemes as $slug) {
            mkdir($root . '/themes/' . $slug, 0770, true);
            file_put_contents($root . '/themes/' . $slug . '/theme.json', json_encode([
                'name' => ucfirst($slug),
                'slug' => $slug,
                'version' => '1.0.0',
                'cms_min_version' => '0.4.0-beta.1',
            ], JSON_THROW_ON_ERROR));
        }

        $database = new ThemeTestDatabase();
        $database->exec("INSERT INTO settings (key, value, type, group_name) VALUES ('theme.active', '" . $activeTheme . "', 'string', 'appearance')");
        foreach ($installedThemes as $slug) {
            $database->prepare(
                'INSERT INTO themes (slug, name, installed_version, manifest, settings) VALUES (:slug, :name, :version, :manifest, :settings)'
            )->execute([
                'slug' => $slug,
                'name' => ucfirst($slug),
                'version' => '1.0.0',
                'manifest' => '{}',
                'settings' => '{}',
            ]);
        }

        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $manager = new ThemeManager(
            new ThemeDetector($root . '/themes'),
            new ThemeRepository($database),
            new ThemeAssetPublisher($root . '/assets'),
            new SettingsRepository($database),
            new ViewRenderer($root, $root . '/cache', true, $translator),
            new EventDispatcher(),
            $translator,
            '0.4.0-beta.1',
        );

        return [$manager, $database];
    }

    private function storedActiveTheme(ThemeTestDatabase $database): string
    {
        return (string) $database->query("SELECT value FROM settings WHERE key = 'theme.active'")->fetchColumn();
    }
}

final class ThemeTestDatabase extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE themes (slug TEXT PRIMARY KEY, name TEXT, installed_version TEXT, manifest TEXT, settings TEXT, installed_at TEXT, updated_at TEXT)');
        $this->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, type TEXT, group_name TEXT, created_at TEXT, updated_at TEXT)');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if (str_starts_with($query, "SHOW TABLES LIKE 'themes'")) {
            $query = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'themes'";
        }

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $query = str_replace(
            'INSERT INTO settings (`key`, `value`, `type`, group_name, created_at, updated_at) VALUES (:key, :value, :type, :group_name, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), group_name = VALUES(group_name), updated_at = UTC_TIMESTAMP()',
            'INSERT INTO settings (`key`, `value`, `type`, group_name) VALUES (:key, :value, :type, :group_name) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`, `type` = excluded.`type`, group_name = excluded.group_name',
            $query,
        );

        return parent::prepare($query, $options);
    }
}
