<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Database\MigrationStatus;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Modules\ModuleRepository;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\System\InstalledSiteHealthCheck;
use NovaNuke\Core\Version;
use NovaNuke\Installer\EnvWriter;
use NovaNuke\Installer\InstallationData;
use NovaNuke\Installer\InstallerService;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;

final class InstallerFreshInstallIntegrationTest extends MySqlIntegrationTestCase
{
    private string $root;

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        // InstallerService must see the harness-created database before any schema exists.
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/novanuke-installer-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0770, true);

        $sourceRoot = dirname(__DIR__, 2);
        foreach (['database', 'language', 'modules', 'themes', 'resources'] as $directory) {
            $this->copyTree($sourceRoot . '/' . $directory, $this->root . '/' . $directory);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    public function testFreshInstallProvisionsAnIsolatedSite(): void
    {
        self::assertSame(0, (int) $this->db()->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn());

        $data = new InstallationData(
            'NovaNuke Integration',
            'https://novanuke.test',
            'en',
            'UTC',
            (string) env('NOVANUKE_TEST_DB_HOST', '127.0.0.1'),
            (int) env('NOVANUKE_TEST_DB_PORT', 3306),
            $this->integrationDatabaseName(),
            (string) env('NOVANUKE_TEST_DB_USERNAME', 'root'),
            (string) env('NOVANUKE_TEST_DB_PASSWORD', ''),
            'beta-admin',
            'beta-admin@example.test',
            'Integration-Password-2026!',
        );

        $migrations = (new InstallerService($this->root, new EnvWriter()))->install($data);

        self::assertNotEmpty($migrations);
        self::assertSame(Version::CURRENT, $this->scalar('SELECT value FROM settings WHERE `key` = \'system.core_version\''));
        $coreMigrations = glob($this->root . '/database/migrations/*.php') ?: [];
        sort($coreMigrations, SORT_STRING);
        self::assertSame(
            basename((string) end($coreMigrations), '.php'),
            $this->scalar('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1'),
        );

        $admin = $this->db()->query(
            "SELECT u.username, u.email, u.status, r.slug AS role_slug
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.username = 'beta-admin'"
        )->fetch();
        self::assertSame('beta-admin', $admin['username']);
        self::assertSame('beta-admin@example.test', $admin['email']);
        self::assertSame('active', $admin['status']);
        self::assertSame('super-administrator', $admin['role_slug']);

        $bundledModules = ['comments', 'downloads', 'friends', 'news', 'pages', 'search', 'web-links'];
        self::assertSame($bundledModules, $this->db()->query('SELECT slug FROM modules ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($bundledModules, $this->db()->query('SELECT slug FROM modules WHERE enabled = 1 ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('novamodern', $this->scalar("SELECT value FROM settings WHERE `key` = 'theme.active'"));
        self::assertSame('novamodern', $this->scalar('SELECT slug FROM themes WHERE slug = \'novamodern\''));
        self::assertSame('1', $this->scalar("SELECT enabled FROM blocks WHERE slug = 'modules'"));

        foreach ([
            'storage/cache',
            'storage/logs',
            'storage/sessions',
            'storage/private',
            'storage/private/downloads',
            'storage/private/backups',
            'storage/private/avatars',
            'public/uploads',
        ] as $directory) {
            self::assertDirectoryIsWritable($this->root . '/' . $directory);
        }
        self::assertFileExists($this->root . '/.env');
        self::assertFileExists($this->root . '/storage/installed.lock');

        $health = (new InstalledSiteHealthCheck(
            $this->root,
            new SettingsRepository($this->db()),
            new MigrationStatus(
                new \NovaNuke\Core\Database\Migrator($this->db()),
                new ModuleMigrator($this->db()),
                $this->moduleManager(),
                $this->root . '/database/migrations',
            ),
        ))->run();
        self::assertNotEmpty($health);
        self::assertNotContains(false, array_column($health, 'passed'));
    }

    private function scalar(string $query): string
    {
        return (string) $this->db()->query($query)->fetchColumn();
    }

    private function moduleManager(): ModuleManager
    {
        $translator = new Translator('en', 'en', $this->root . '/language');
        return new ModuleManager(
            $this->db(),
            new ModuleDetector($this->root . '/modules'),
            new ModuleRepository($this->db()),
            new ModuleMigrator($this->db()),
            new ModuleCompatibilityChecker(Version::CURRENT),
            new Container(),
            new Router(),
            new EventDispatcher(),
            $translator,
        );
    }

    private function copyTree(string $source, string $destination): void
    {
        if (is_dir($source)) {
            if (! is_dir($destination)) mkdir($destination, 0770, true);
            foreach (new \FilesystemIterator($source) as $item) {
                $this->copyTree($item->getPathname(), $destination . '/' . $item->getBasename());
            }
            return;
        }
        if (! is_dir(dirname($destination))) mkdir(dirname($destination), 0770, true);
        copy($source, $destination);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
