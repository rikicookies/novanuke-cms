<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Database\MigrationInterruption;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Modules\ModuleRepository;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use RuntimeException;

final class ModuleInstallationRecoveryTest extends MySqlIntegrationTestCase
{
    private string $root;
    private string $modulePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/novanuke-module-recovery-' . bin2hex(random_bytes(6));
        $this->modulePath = $this->root . '/RecoveryFixture';
        mkdir($this->modulePath . '/database/migrations', 0770, true);
        file_put_contents($this->modulePath . '/module.json', json_encode([
            'name' => 'Recovery Fixture',
            'slug' => 'recovery-fixture',
            'version' => '1.0.0',
            'provider' => 'Modules\\RecoveryFixture\\RecoveryFixtureModule',
            'cms_min_version' => '0.4.0-beta.1',
            'php_min_version' => '8.3.0',
            'permissions' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->modulePath . '/database/migrations/2026_09_20_000001_create_recovery_fixture.php', <<<'PHP'
<?php

use NovaNuke\Core\Database\RecoverableMigration;

return new class implements RecoverableMigration {
    public function up(\PDO $database): void
    {
        $database->exec('CREATE TABLE recovery_fixture (id INT PRIMARY KEY) ENGINE=InnoDB');
    }

    public function down(\PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS recovery_fixture');
    }

    public function isApplied(\PDO $database): bool
    {
        return (bool) $database->query("SHOW TABLES LIKE 'recovery_fixture'")->fetchColumn();
    }

    public function isRolledBack(\PDO $database): bool
    {
        return ! (bool) $database->query("SHOW TABLES LIKE 'recovery_fixture'")->fetchColumn();
    }
};
PHP);
        file_put_contents($this->modulePath . '/database/migrations/2026_09_20_000002_add_recovery_fixture_column.php', <<<'PHP'
<?php

use NovaNuke\Core\Database\RecoverableMigration;

return new class implements RecoverableMigration {
    public function up(\PDO $database): void
    {
        $database->exec('ALTER TABLE recovery_fixture ADD COLUMN marker VARCHAR(20) NULL');
    }

    public function down(\PDO $database): void
    {
        $database->exec('ALTER TABLE recovery_fixture DROP COLUMN marker');
    }

    public function isApplied(\PDO $database): bool
    {
        $statement = $database->query("SHOW COLUMNS FROM recovery_fixture LIKE 'marker'");
        return $statement->fetchColumn() !== false;
    }

    public function isRolledBack(\PDO $database): bool
    {
        return ! $this->isApplied($database);
    }
};
PHP);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testLaterMigrationFailureLeavesModuleUninstalledAndExplicitRecoveryCompletesInstall(): void
    {
        $interrupted = false;
        $migrator = new ModuleMigrator($this->db(), static function (string $stage, string $scope, string $name) use (&$interrupted): void {
            if (! $interrupted && $stage === 'after_up' && str_contains($name, '000002_add_recovery_fixture_column')) {
                $interrupted = true;
                throw new MigrationInterruption('simulated process interruption after migration DDL');
            }
        });
        $manager = $this->manager($migrator);

        try {
            $manager->install('recovery-fixture');
            self::fail('Expected the second migration to be interrupted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Module migration failed: recovery-fixture.', $error->getMessage());
        }

        self::assertFalse($manager->inventory()['recovery-fixture']['installed']);
        $manifest = (new ModuleDetector($this->root))->detect()['recovery-fixture'];
        $status = $migrator->status($manifest);
        self::assertCount(1, $status['recovery']);
        self::assertSame('running', $status['recovery'][0]['state']);
        self::assertTrue((bool) $this->db()->query("SHOW COLUMNS FROM recovery_fixture LIKE 'marker'")->fetchColumn());

        self::assertSame(['2026_09_20_000002_add_recovery_fixture_column'], $manager->recover('recovery-fixture'));
        $manager->install('recovery-fixture');

        self::assertArrayHasKey('recovery-fixture', $manager->inventory());
        self::assertTrue($manager->inventory()['recovery-fixture']['installed']);
        self::assertSame([], $migrator->status($manifest)['recovery']);
    }

    private function manager(ModuleMigrator $migrator): ModuleManager
    {
        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        return new ModuleManager(
            $this->db(),
            new ModuleDetector($this->root),
            new ModuleRepository($this->db()),
            $migrator,
            new ModuleCompatibilityChecker('0.4.0-beta.1'),
            new Container(),
            new Router(),
            new EventDispatcher(),
            $translator,
        );
    }
}
