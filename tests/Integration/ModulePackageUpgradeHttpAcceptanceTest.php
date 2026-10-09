<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Modules\ModulePackageInstaller;
use NovaNuke\Core\Modules\ModulePackageUploadValidator;
use NovaNuke\Core\Modules\ModuleRepository;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Admin\ModulesController;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;
use ZipArchive;

final class ModulePackageUpgradeHttpAcceptanceTest extends MySqlIntegrationTestCase
{
    private string $root;
    private SessionManager $session;
    private CsrfTokenManager $csrf;
    private ModulesController $controller;
    private ModuleRepository $repository;
    private ModuleManager $manager;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->root = sys_get_temp_dir() . '/novanuke-module-http-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/modules', 0770, true);
        $this->repository = new ModuleRepository($this->db());
        $this->manager = new ModuleManager(
            $this->db(), new ModuleDetector($this->root . '/modules'), $this->repository,
            new ModuleMigrator($this->db()), new ModuleCompatibilityChecker('0.4.0-beta.1'),
            new Container(), new Router(), new EventDispatcher(),
            new Translator('en', 'en', dirname(__DIR__, 2) . '/language'),
        );
        $this->adminId = $this->userWithRole('super-administrator');
        $this->session = new SessionManager('novanuke_module_http_test', false);
        $this->csrf = new CsrfTokenManager($this->session);
        $this->controller = $this->controllerFor(new Translator('en', 'en', dirname(__DIR__, 2) . '/language'));
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

    public function testAnonymousUnauthorizedAndCsrfRequestsAreRejectedByRealControllerGuards(): void
    {
        self::assertSame(302, $this->controller->upload($this->request())->status());

        $this->authenticate($this->adminId);
        self::assertSame(419, $this->controller->upload($this->request(['_token' => '']))->status());
        self::assertSame(419, $this->controller->upload($this->request(['_token' => 'invalid']))->status());

        $user = $this->userWithRole(null);
        $this->authenticate($user);
        self::assertSame(403, $this->controller->upload($this->request(['_token' => $this->csrf->token()]))->status());
    }

    public function testAuthorizedControllerUploadsFreshPackageThenPerformsUpgrade(): void
    {
        $this->authenticate($this->adminId);
        $first = $this->package('1.0.0', 'first source');
        $response = $this->controller->upload($this->request(['_token' => $this->csrf->token()], $first));
        self::assertSame(200, $response->status(), $response->content());
        self::assertSame('first source', file_get_contents($this->root . '/modules/UpgradeFixture/source.txt'));

        $this->manager->install('upgrade-fixture');
        $this->session->remove('_csrf_token');
        $second = $this->package('2.0.0', 'second source');
        $response = $this->controller->upload($this->request(['_token' => $this->csrf->token()], $second));

        self::assertSame(200, $response->status(), $response->content());
        self::assertSame('second source', file_get_contents($this->root . '/modules/UpgradeFixture/source.txt'));
        self::assertSame('2.0.0', $this->repository->all()['upgrade-fixture']['installed_version']);
        self::assertFalse((bool) $this->repository->all()['upgrade-fixture']['enabled']);
        self::assertStringContainsString('updated', strtolower($response->content()));
    }

    public function testDowngradeAndInvalidArchiveReturnActionableControllerErrors(): void
    {
        $this->authenticate($this->adminId);
        $first = $this->package('2.0.0', 'current source');
        $this->controller->upload($this->request(['_token' => $this->csrf->token()], $first));
        $this->manager->install('upgrade-fixture');

        $this->session->remove('_csrf_token');
        $downgrade = $this->package('1.0.0', 'old source');
        $response = $this->controller->upload($this->request(['_token' => $this->csrf->token()], $downgrade));
        self::assertSame(422, $response->status());
        self::assertStringContainsString('newer', strtolower($response->content()));
        self::assertSame('current source', file_get_contents($this->root . '/modules/UpgradeFixture/source.txt'));

        $invalid = $this->root . '/invalid.zip';
        file_put_contents($invalid, 'not a zip');
        $this->session->remove('_csrf_token');
        $response = $this->controller->upload($this->request(['_token' => $this->csrf->token()], [
            'name' => 'invalid.zip', 'tmp_name' => $invalid, 'size' => filesize($invalid), 'error' => UPLOAD_ERR_OK,
        ]));
        self::assertSame(422, $response->status());
        self::assertStringContainsString('ZIP', $response->content());
    }

    public function testSpanishPackageErrorUsesThePublishedTranslationCatalogue(): void
    {
        $this->authenticate($this->adminId);
        $this->controller = $this->controllerFor(new Translator('es', 'en', dirname(__DIR__, 2) . '/language'));
        $invalid = $this->root . '/invalid-es.zip';
        file_put_contents($invalid, 'not a zip');
        $response = $this->controller->upload($this->request(['_token' => $this->csrf->token()], [
            'name' => 'invalid-es.zip', 'tmp_name' => $invalid, 'size' => filesize($invalid), 'error' => UPLOAD_ERR_OK,
        ]));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('El archivo subido no es un paquete ZIP.', $response->content());
    }

    public function testConflictingPackageIdentityCannotReplaceExistingDestination(): void
    {
        $this->authenticate($this->adminId);
        $first = $this->package('1.0.0', 'current source');
        $this->controller->upload($this->request(['_token' => $this->csrf->token()], $first));

        $this->session->remove('_csrf_token');
        $conflict = $this->package('2.0.0', 'conflicting source', 'different-module');
        $response = $this->controller->upload($this->request(['_token' => $this->csrf->token()], $conflict));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('destination already exists', strtolower($response->content()));
        self::assertSame('current source', file_get_contents($this->root . '/modules/UpgradeFixture/source.txt'));
    }

    public function testMigrationFailureReturnsActionableResponseAndKeepsRecoveryBackup(): void
    {
        $this->authenticate($this->adminId);
        $first = $this->package('1.0.0', 'current source');
        $this->controller->upload($this->request(['_token' => $this->csrf->token()], $first));
        $this->manager->install('upgrade-fixture');

        $this->manager = $this->managerWithFault(static function (): void {
            throw new \RuntimeException('simulated migration failure');
        });
        $this->session->remove('_csrf_token');
        $this->controller = $this->controllerFor(new Translator('en', 'en', dirname(__DIR__, 2) . '/language'));
        $upgrade = $this->package('2.0.0', 'new source', 'upgrade-fixture', true);
        $response = $this->controller->upload($this->request(['_token' => $this->csrf->token()], $upgrade));

        self::assertSame(422, $response->status());
        self::assertStringContainsString('database update did not complete', strtolower($response->content()));
        self::assertSame('new source', file_get_contents($this->root . '/modules/UpgradeFixture/source.txt'));
        $backups = glob($this->root . '/storage/private/module-updates/upgrade-fixture/*/previous/source.txt') ?: [];
        self::assertCount(1, $backups);
        self::assertSame('current source', file_get_contents($backups[0]));
    }

    private function controllerFor(Translator $translator): ModulesController
    {
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-module-http-view-cache', true, $translator);
        foreach (['cms_name' => 'NovaNuke Test', 'cms_locale' => 'en', 'cms_locales' => ['en' => 'English', 'es' => 'Español'], 'cms_version' => '0.4.0-beta.1', 'current_user' => null, 'admin_navigation' => [], 'blocks' => [], 'menus' => ['primary' => []]] as $key => $value) $views->addGlobal($key, $value);
        $auth = new AuthManager($this->db(), $this->session, new EventDispatcher());
        return new ModulesController(
            $this->manager, $auth, new AuthorizationService($this->db()), new ActivityLogger($this->db()),
            $this->csrf, $views, $translator, new ModulePackageInstaller($this->root . '/modules', new ModuleCompatibilityChecker('0.4.0-beta.1')),
            new ModulePackageUploadValidator(static fn (string $path): bool => true), $this->repository,
        );
    }

    private function managerWithFault(?\Closure $fault): ModuleManager
    {
        return new ModuleManager(
            $this->db(), new ModuleDetector($this->root . '/modules'), $this->repository,
            new ModuleMigrator($this->db(), $fault), new ModuleCompatibilityChecker('0.4.0-beta.1'),
            new Container(), new Router(), new EventDispatcher(),
            new Translator('en', 'en', dirname(__DIR__, 2) . '/language'),
        );
    }

    private function request(array $input = [], ?array $file = null): Request
    {
        return new Request('POST', '/admin/modules/package', [], $input, [], $file === null ? [] : ['module_package' => $file], ['REMOTE_ADDR' => '127.0.0.1']);
    }

    private function authenticate(int $id): void
    {
        $_SESSION = ['_auth_user_id' => $id, '_auth_version' => 1];
    }

    private function userWithRole(?string $role): int
    {
        $this->db()->prepare('INSERT INTO users (username,email,password_hash,status,auth_version,created_at,updated_at) VALUES (:u,:e,:p,"active",1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([
            'u' => 'module-http-' . bin2hex(random_bytes(3)), 'e' => bin2hex(random_bytes(4)) . '@example.test', 'p' => password_hash('not-used', PASSWORD_DEFAULT),
        ]);
        $id = (int) $this->db()->lastInsertId();
        if ($role !== null) {
            $statement = $this->db()->prepare('INSERT INTO user_roles (user_id,role_id,created_at) SELECT :user_id,id,UTC_TIMESTAMP() FROM roles WHERE slug=:role');
            $statement->execute(['user_id' => $id, 'role' => $role]);
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function package(string $version, string $content, string $slug = 'upgrade-fixture', bool $withFailingMigration = false): array
    {
        $source = $this->root . '/package/UpgradeFixture';
        if (is_dir(dirname($source))) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($source), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        mkdir($source, 0770, true);
        file_put_contents($source . '/module.json', json_encode(['name'=>'Upgrade Fixture','slug'=>$slug,'version'=>$version,'provider'=>'Modules\\UpgradeFixture\\UpgradeFixtureModule','cms_min_version'=>'0.4.0-beta.1','php_min_version'=>'8.3.0','permissions'=>[]], JSON_THROW_ON_ERROR));
        file_put_contents($source . '/UpgradeFixtureModule.php', '<?php namespace Modules\\UpgradeFixture;');
        file_put_contents($source . '/source.txt', $content);
        if ($withFailingMigration) {
            mkdir($source . '/database/migrations', 0770, true);
            file_put_contents($source . '/database/migrations/2026_10_09_000001_fail_upgrade.php', <<<'PHP'
<?php
use NovaNuke\Core\Database\Migration;
return new class implements Migration {
 public function up(\PDO $database): void { throw new RuntimeException('fixture migration failure'); }
 public function down(\PDO $database): void {}
};
PHP);
        }
        $archive = $this->root . '/package-' . $version . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($source), \FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($source)) + 1)));
        $zip->close();
        return ['name' => basename($archive), 'tmp_name' => $archive, 'size' => filesize($archive), 'error' => UPLOAD_ERR_OK];
    }
}
