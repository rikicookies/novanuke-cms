<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Modules\ModulePackageInstaller;
use NovaNuke\Core\Modules\ModuleRepository;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use ZipArchive;

final class ModulePackageUpgradeIntegrationTest extends MySqlIntegrationTestCase
{
    private string $root;
    private string $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/novanuke-module-upgrade-' . bin2hex(random_bytes(6));
        $this->module = $this->root . '/UpgradeFixture';
        mkdir($this->module . '/database/migrations', 0770, true);
        file_put_contents($this->module . '/module.json', $this->manifest('1.0.0'));
        file_put_contents($this->module . '/UpgradeFixtureModule.php', '<?php namespace Modules\\UpgradeFixture;');
        file_put_contents($this->module . '/database/migrations/2026_10_09_000001_create_upgrade_fixture.php', $this->migrationOne());
        file_put_contents($this->module . '/old.txt', 'old source');
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testUpgradePreservesDataAndRunsNewMigrationInDisposableMysql(): void
    {
        $manager = $this->manager();
        $manager->install('upgrade-fixture');
        $this->db()->exec("INSERT INTO upgrade_fixture (value) VALUES ('preserved')");
        $before = $this->db()->query('SELECT COUNT(*) FROM upgrade_fixture')->fetchColumn();

        $archive = $this->archive();
        $repository = new ModuleRepository($this->db());
        $installer = new ModulePackageInstaller($this->root, new ModuleCompatibilityChecker('0.4.0-beta.1'));
        $installer->upgrade($archive, $repository->all(), fn ($manifest): mixed => $manager->update($manifest->slug));

        self::assertSame('new source', file_get_contents($this->module . '/new.txt'));
        self::assertFileDoesNotExist($this->module . '/old.txt');
        self::assertSame($before, $this->db()->query('SELECT COUNT(*) FROM upgrade_fixture')->fetchColumn());
        self::assertNotFalse($this->db()->query("SHOW COLUMNS FROM upgrade_fixture LIKE 'marker'")->fetchColumn());
        self::assertSame('2.0.0', (new ModuleRepository($this->db()))->all()['upgrade-fixture']['installed_version']);
        self::assertSame('2', (string) $this->db()->query("SELECT COUNT(*) FROM module_migrations WHERE module_slug='upgrade-fixture'")->fetchColumn());
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager(
            $this->db(), new ModuleDetector($this->root), new ModuleRepository($this->db()),
            new ModuleMigrator($this->db()), new ModuleCompatibilityChecker('0.4.0-beta.1'),
            new Container(), new Router(), new EventDispatcher(),
            new Translator('en', 'en', dirname(__DIR__, 2) . '/language'),
        );
    }

    private function archive(): string
    {
        $source = $this->root . '/package/UpgradeFixture';
        mkdir($source . '/database/migrations', 0770, true);
        file_put_contents($source . '/module.json', $this->manifest('2.0.0'));
        file_put_contents($source . '/UpgradeFixtureModule.php', '<?php namespace Modules\\UpgradeFixture;');
        file_put_contents($source . '/database/migrations/2026_10_09_000001_create_upgrade_fixture.php', $this->migrationOne());
        file_put_contents($source . '/database/migrations/2026_10_09_000002_add_upgrade_fixture_marker.php', $this->migrationTwo());
        file_put_contents($source . '/new.txt', 'new source');
        $archive = $this->root . '/upgrade.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($source), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($source)) + 1)));
        }
        $zip->close();
        return $archive;
    }

    private function manifest(string $version): string
    {
        return json_encode(['name'=>'Upgrade Fixture','slug'=>'upgrade-fixture','version'=>$version,'provider'=>'Modules\\UpgradeFixture\\UpgradeFixtureModule','cms_min_version'=>'0.4.0-beta.1','php_min_version'=>'8.3.0','permissions'=>[]], JSON_THROW_ON_ERROR);
    }

    private function migrationOne(): string
    {
        return <<<'PHP'
<?php
use NovaNuke\Core\Database\RecoverableMigration;
return new class implements RecoverableMigration {
 public function up(\PDO $d): void {$d->exec("CREATE TABLE upgrade_fixture (id INT PRIMARY KEY AUTO_INCREMENT, value VARCHAR(40) NOT NULL) ENGINE=InnoDB");}
 public function down(\PDO $d): void {$d->exec('DROP TABLE IF EXISTS upgrade_fixture');}
 public function isApplied(\PDO $d): bool {return (bool)$d->query("SHOW TABLES LIKE 'upgrade_fixture'")->fetchColumn();}
 public function isRolledBack(\PDO $d): bool {return !$this->isApplied($d);}
};
PHP;
    }

    private function migrationTwo(): string
    {
        return <<<'PHP'
<?php
use NovaNuke\Core\Database\RecoverableMigration;
return new class implements RecoverableMigration {
 public function up(\PDO $d): void {$d->exec("ALTER TABLE upgrade_fixture ADD COLUMN marker VARCHAR(40) NULL");}
 public function down(\PDO $d): void {$d->exec('ALTER TABLE upgrade_fixture DROP COLUMN marker');}
 public function isApplied(\PDO $d): bool {return (bool)$d->query("SHOW COLUMNS FROM upgrade_fixture LIKE 'marker'")->fetchColumn();}
 public function isRolledBack(\PDO $d): bool {return !$this->isApplied($d);}
};
PHP;
    }
}
