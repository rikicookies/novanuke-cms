<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Modules\ModuleRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MissingModuleLifecycleTest extends TestCase
{
    private MissingModuleDatabase $database;
    private string $modulesPath;
    private ModuleManager $manager;

    protected function setUp(): void
    {
        $this->database = new MissingModuleDatabase();
        $this->modulesPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'novanuke-missing-' . bin2hex(random_bytes(4));
        mkdir($this->modulesPath);
        $this->manager = new ModuleManager(
            $this->database,
            new ModuleDetector($this->modulesPath),
            new ModuleRepository($this->database),
            new ModuleMigrator($this->database),
            new ModuleCompatibilityChecker('0.4.0-beta.1'),
            new Container(),
            new Router(),
            new EventDispatcher(),
            new Translator('en', 'en', dirname(__DIR__, 2) . '/language'),
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->modulesPath)) {
            foreach (glob($this->modulesPath . '/*') ?: [] as $path) {
                $this->removeTree($path);
            }
            rmdir($this->modulesPath);
        }
    }

    public function testRegisteredAvailableModuleIsNormalAndMissingModuleIsExplicitlyMarked(): void
    {
        $this->insertModule('availablefixture');
        mkdir($this->modulesPath . '/Availablefixture');
        mkdir($this->modulesPath . '/Availablefixture/src');
        file_put_contents($this->modulesPath . '/Availablefixture/module.json', json_encode($this->manifest('availablefixture'), JSON_THROW_ON_ERROR));
        file_put_contents($this->modulesPath . '/Availablefixture/src/FixtureModule.php', '<?php');

        $this->insertModule('missingfixture');
        $inventory = $this->manager->inventory();

        self::assertFalse($inventory['availablefixture']['missing_files']);
        self::assertTrue($inventory['missingfixture']['missing_files']);
        self::assertTrue($inventory['missingfixture']['installed']);
        self::assertFalse($inventory['missingfixture']['compatible']);
    }

    public function testMissingModuleCannotUseSourceDependentOperations(): void
    {
        $this->insertModule('missingfixture');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Module files were not found.');
        $this->manager->enable('missingfixture');
    }

    public function testMissingRegistrationSurvivesRepeatedInventoryAndBootWithoutMetadataMutation(): void
    {
        $this->insertModule('missingfixture');
        $this->database->exec("UPDATE modules SET enabled = 1, last_error = 'existing state' WHERE slug = 'missingfixture'");

        for ($i = 0; $i < 3; $i++) {
            $inventory = $this->manager->inventory();
            self::assertTrue($inventory['missingfixture']['missing_files']);
        }

        $this->manager->bootEnabled();

        self::assertSame(1, (int) $this->database->query("SELECT COUNT(*) FROM modules WHERE slug = 'missingfixture'")->fetchColumn());
        self::assertSame('existing state', $this->database->query("SELECT last_error FROM modules WHERE slug = 'missingfixture'")->fetchColumn());
        self::assertTrue($this->manager->inventory()['missingfixture']['missing_files']);
    }

    public function testForgetMissingRemovesOnlyGenericRegistryMetadata(): void
    {
        $this->insertModule('missingfixture');
        $permission = $this->database->query("INSERT INTO permissions (slug, module_slug) VALUES ('missingfixture.manage', 'missingfixture')");
        $permissionId = (int) $this->database->lastInsertId();
        $this->database->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES (1, {$permissionId})");
        $this->database->exec("INSERT INTO module_migrations (module_slug, migration) VALUES ('missingfixture', '2026_01_01_create_data')");
        $this->database->exec("CREATE TABLE module_owned_data (id INTEGER PRIMARY KEY, value TEXT)");
        $this->database->exec("INSERT INTO module_owned_data (value) VALUES ('preserve me')");
        $this->database->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
        $this->database->exec("INSERT INTO settings (key, value) VALUES ('missingfixture.custom', 'preserve me')");

        $this->manager->forgetMissing('missingfixture');

        self::assertSame(0, (int) $this->database->query("SELECT COUNT(*) FROM modules WHERE slug = 'missingfixture'")->fetchColumn());
        self::assertSame(0, (int) $this->database->query("SELECT COUNT(*) FROM module_migrations WHERE module_slug = 'missingfixture'")->fetchColumn());
        self::assertSame(0, (int) $this->database->query("SELECT COUNT(*) FROM permissions WHERE module_slug = 'missingfixture'")->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn());
        self::assertSame('preserve me', $this->database->query('SELECT value FROM module_owned_data')->fetchColumn());
        self::assertSame('preserve me', $this->database->query("SELECT value FROM settings WHERE key = 'missingfixture.custom'")->fetchColumn());
    }

    public function testForgetMissingRejectsInvalidSlugAndAvailableSource(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid module slug.');
        $this->manager->forgetMissing('INVALID_SLUG');
    }

    public function testForgetMissingRejectsWhenSourceBecomesAvailable(): void
    {
        $this->insertModule('availablefixture');
        mkdir($this->modulesPath . '/Availablefixture');
        mkdir($this->modulesPath . '/Availablefixture/src');
        file_put_contents($this->modulesPath . '/Availablefixture/module.json', json_encode($this->manifest('availablefixture'), JSON_THROW_ON_ERROR));
        file_put_contents($this->modulesPath . '/Availablefixture/src/FixtureModule.php', '<?php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Module source is available; use normal uninstall.');
        $this->manager->forgetMissing('availablefixture');
    }

    public function testAdminMissingModuleContractIsTranslatedProtectedPostOnlyAndGeneric(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/app/Admin/ModulesController.php');
        $template = (string) file_get_contents($root . '/resources/views/admin/modules/index.twig');
        $manager = (string) file_get_contents($root . '/app/Core/Modules/ModuleManager.php');
        $english = json_decode((string) file_get_contents($root . '/language/en.json'), true, 128, JSON_THROW_ON_ERROR);
        $spanish = json_decode((string) file_get_contents($root . '/language/es.json'), true, 128, JSON_THROW_ON_ERROR);

        foreach (['forgetMissing', 'forget-missing', 'csrf->validate', 'modules.manage', 'activity->log'] as $needle) {
            self::assertStringContainsString($needle, $controller . $manager);
        }
        foreach (['missing_files', 'admin.modules.missing_title', 'admin.modules.forget_missing_help', '/forget-missing', 'data-ajax-action'] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        foreach (['admin.modules.missing_title', 'admin.modules.forget_missing_help', 'admin.modules.message.forget-missing'] as $key) {
            self::assertArrayHasKey($key, $english);
            self::assertArrayHasKey($key, $spanish);
        }
        self::assertStringNotContainsString('Landing', $manager . $controller);
        self::assertStringNotContainsString('DemoContent', $manager . $controller);
    }

    /** @return array<string,mixed> */
    private function manifest(string $slug): array
    {
        return [
            'name' => 'Fixture',
            'slug' => $slug,
            'version' => '1.0.0',
            'api_version' => '1.0',
            'description' => 'Fixture',
            'author' => 'Tests',
            'provider' => 'Modules\\' . str_replace('-', '', ucwords($slug, '-')) . '\\src\\FixtureModule',
            'cms_min_version' => '0.4.0-beta.1',
            'php_min_version' => '8.3.0',
            'dependencies' => [],
            'permissions' => [],
            'navigation' => null,
        ];
    }

    private function insertModule(string $slug): void
    {
        $statement = $this->database->prepare('INSERT INTO modules (slug, name, installed_version, enabled, audience, manifest, installed_at, updated_at, last_error) VALUES (?, ?, ?, 0, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL)');
        $statement->execute([$slug, 'Fixture', '1.0.0', 'public', '{}']);
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (glob($path . '/*') ?: [] as $child) $this->removeTree($child);
            rmdir($path);
            return;
        }
        if (is_file($path)) unlink($path);
    }
}

final class MissingModuleDatabase extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->exec('PRAGMA foreign_keys = ON');
        $this->exec('CREATE TABLE modules (slug TEXT PRIMARY KEY, name TEXT, installed_version TEXT, enabled INTEGER, audience TEXT, manifest TEXT, installed_at TEXT, updated_at TEXT, last_error TEXT)');
        $this->exec('CREATE TABLE module_migrations (module_slug TEXT, migration TEXT)');
        $this->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, module_slug TEXT)');
        $this->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE)');
    }
}
