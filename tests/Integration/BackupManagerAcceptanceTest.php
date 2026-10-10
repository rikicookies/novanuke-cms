<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Admin\BackupManagerController;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Backup\BackupOperationLock;
use NovaNuke\Core\Backup\BackupExportBundle;
use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Core\Backup\BackupSetStatus;
use NovaNuke\Core\Backup\BackupVerifier;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;
use PDO;

final class BackupManagerAcceptanceTest extends MySqlIntegrationTestCase
{
    private string $root;
    private string $backupDirectory;
    private SessionManager $session;
    private CsrfTokenManager $csrf;
    private BackupSetCoordinator $coordinator;
    private BackupManagerController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->root = sys_get_temp_dir() . '/novanuke-backup-manager-' . bin2hex(random_bytes(6));
        $this->backupDirectory = $this->root . '/storage/private/backups';
        foreach (['modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki', 'storage/private/backups'] as $path) {
            mkdir($this->root . '/' . $path, 0700, true);
        }
        file_put_contents($this->root . '/modules/fixture.txt', 'fixture');
        $this->coordinator = new BackupSetCoordinator($this->db(), $this->root, $this->backupDirectory);
        $this->session = new SessionManager('novanuke_backup_acceptance', false);
        $this->csrf = new CsrfTokenManager($this->session);
        $translator = new Translator('en', 'en', dirname(__DIR__, 2) . '/language');
        $views = new ViewRenderer(dirname(__DIR__, 2) . '/resources/views', sys_get_temp_dir() . '/novanuke-backup-view-' . bin2hex(random_bytes(4)), true, $translator);
        $views->addNamespace('admin-core', dirname(__DIR__, 2) . '/resources/views');
        foreach (['cms_name'=>'NovaNuke Test','cms_locale'=>'en','cms_locales'=>['en'=>'English','es'=>'Español'],'cms_subtitle'=>'Test','cms_version'=>'0.4.0-beta.1','current_user'=>null,'admin_navigation'=>[]] as $key=>$value) $views->addGlobal($key, $value);
        $this->controller = new BackupManagerController(
            new AuthManager($this->db(), $this->session, new EventDispatcher()),
            new AuthorizationService($this->db()),
            new ActivityLogger($this->db()), $this->csrf, $this->session, $views,
            new BackupSetStatus($this->backupDirectory), $this->coordinator,
            new BackupVerifier($this->backupDirectory), $this->backupDirectory, $translator,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testAnonymousAndUnauthorizedUsersCannotListBackups(): void
    {
        self::assertSame(302, $this->controller->index()->status());
        $member = $this->createUser('backup-member'); $this->loginAs($member);
        self::assertSame(403, $this->controller->index()->status());
        $manager = $this->createUser('backup-news-manager'); $this->grantRole($manager, 'administrator'); $this->loginAs($manager);
        self::assertSame(403, $this->controller->index()->status());
    }

    public function testAuthorizedAdministratorListsEmptyStateAndCreatesPlaintextSet(): void
    {
        $admin = $this->createUser('backup-admin'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        $empty = $this->controller->index();
        self::assertSame(200, $empty->status()); self::assertStringContainsString('No backup sets', $empty->content());
        $request = $this->request('POST', '/admin/backups/create', ['_token' => $this->csrf->token(), 'confirm' => '1']);
        self::assertSame(303, $this->controller->create($request)->status());
        $sets = glob($this->backupDirectory . '/set-*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $sets);
        self::assertFileExists($sets[0] . '/manifest.json');
        self::assertStringContainsString('Plaintext', $this->controller->index()->content());
    }

    public function testCreateRequiresConfirmationAndValidCsrf(): void
    {
        $admin = $this->createUser('backup-csrf'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        self::assertSame(419, $this->controller->create($this->request('POST', '/admin/backups/create', []))->status());
        self::assertSame(419, $this->controller->create($this->request('POST', '/admin/backups/create', ['_token' => 'invalid', 'confirm' => '1']))->status());
        self::assertSame(422, $this->controller->create($this->request('POST', '/admin/backups/create', ['_token' => $this->csrf->token()]))->status());
        self::assertSame([], glob($this->backupDirectory . '/set-*', GLOB_ONLYDIR) ?: []);
    }

    public function testAuthorizedAdministratorVerifiesRealSetAndRejectsInvalidIds(): void
    {
        $admin = $this->createUser('backup-verify'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        $set = $this->coordinator->create();
        $request = $this->request('POST', '/admin/backups/' . $set['backup_set_id'] . '/verify', ['_token' => $this->csrf->token()])->withAttributes(['id' => $set['backup_set_id']]);
        self::assertSame(303, $this->controller->verify($request)->status());
        $invalid = $this->request('POST', '/admin/backups/../../etc/passwd/verify', ['_token' => $this->csrf->token()])->withAttributes(['id' => '../../etc/passwd']);
        self::assertSame(404, $this->controller->verify($invalid)->status());
        self::assertStringNotContainsString($this->root, $this->controller->index()->content());
    }

    public function testExportStreamsVerifiedBundleAndCleansTemporaryFile(): void
    {
        $admin = $this->createUser('backup-export'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        $set = $this->coordinator->create();
        $request = $this->request('POST', '/admin/backups/' . $set['backup_set_id'] . '/export', ['_token' => $this->csrf->token()])->withAttributes(['id' => $set['backup_set_id']]);
        $response = $this->controller->export($request);
        self::assertSame(200, $response->status());
        self::assertSame('application/x-tar', $response->header('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $response->header('Content-Disposition'));
        self::assertSame('private, no-store', $response->header('Cache-Control'));
        ob_start(); $response->send(); $body = (string) ob_get_clean();
        $captured = $this->root . '/captured-export.tar';
        file_put_contents($captured, $body);
        self::assertSame($set['backup_set_id'], (new BackupExportBundle($this->backupDirectory))->verify($captured)['backup_set_id']);
        @unlink($captured);
        self::assertSame([], glob($this->backupDirectory . '/.export-*.tar', GLOB_NOSORT) ?: []);
    }

    public function testExportRequiresPermissionCsrfAndStrictId(): void
    {
        $set = $this->coordinator->create();
        $request = $this->request('POST', '/admin/backups/' . $set['backup_set_id'] . '/export', ['_token' => $this->csrf->token()])->withAttributes(['id' => $set['backup_set_id']]);
        self::assertSame(302, $this->controller->export($request)->status());
        $member = $this->createUser('backup-export-member'); $this->loginAs($member);
        self::assertSame(403, $this->controller->export($request)->status());
        $admin = $this->createUser('backup-export-csrf'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        self::assertSame(419, $this->controller->export($this->request('POST', '/admin/backups/x/export', ['_token' => 'invalid'])->withAttributes(['id' => 'x']))->status());
        self::assertSame(404, $this->controller->export($this->request('POST', '/admin/backups/x/export', ['_token' => $this->csrf->token()])->withAttributes(['id' => '../x']))->status());
    }

    public function testCorruptSetFailsVerificationWithoutExposingPaths(): void
    {
        $admin = $this->createUser('backup-corrupt'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        $set = $this->coordinator->create();
        file_put_contents($set['manifest'], '{"corrupt":true}');
        $request = $this->request('POST', '/admin/backups/' . $set['backup_set_id'] . '/verify', ['_token' => $this->csrf->token()])->withAttributes(['id' => $set['backup_set_id']]);
        self::assertSame(422, $this->controller->verify($request)->status());
        self::assertStringNotContainsString($this->root, $this->controller->index()->content());
    }

    public function testEncryptedSetShowsSecretRequiredInsteadOfFalseCorruption(): void
    {
        $admin = $this->createUser('backup-encrypted'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        $this->coordinator->create('operator-only-secret');
        $page = $this->controller->index();
        self::assertSame(200, $page->status()); self::assertStringContainsString('Secret required', $page->content());
        self::assertStringNotContainsString('operator-only-secret', $page->content());
    }

    public function testConcurrentOperationIsRejectedAndDoesNotCreateSet(): void
    {
        $admin = $this->createUser('backup-lock'); $this->grantSuperAdministrator($admin); $this->loginAs($admin);
        $lock = new BackupOperationLock($this->backupDirectory); $lock->acquire();
        try {
            $response = $this->controller->create($this->request('POST', '/admin/backups/create', ['_token' => $this->csrf->token(), 'confirm' => '1']));
            self::assertSame(422, $response->status()); self::assertStringContainsString('already running', $response->content());
            self::assertSame([], glob($this->backupDirectory . '/set-*', GLOB_ONLYDIR) ?: []);
        } finally { $lock->release(); }
    }

    public function testSeparateProcessCannotAcquireHeldLock(): void
    {
        $ready = $this->root . '/child-ready'; $release = $this->root . '/child-release';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $childScript = $this->root . '/lock-child.php';
        file_put_contents($childScript, '<?php require $argv[1]; $l=new \\NovaNuke\\Core\\Backup\\BackupOperationLock($argv[2]); $l->acquire(); file_put_contents($argv[3], "ready"); while (!is_file($argv[4])) usleep(10000); $l->release();');
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childScript) . ' ' . escapeshellarg($autoload) . ' ' . escapeshellarg($this->backupDirectory) . ' ' . escapeshellarg($ready) . ' ' . escapeshellarg($release);
        $pipes = []; $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            for ($i = 0; $i < 100 && ! is_file($ready); $i++) usleep(10000);
            self::assertFileExists($ready);
            $lock = new BackupOperationLock($this->backupDirectory);
            try { $lock->acquire(); self::fail('A second process acquired the held backup lock.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('already running', $error->getMessage()); }
            finally { $lock->release(); }
            file_put_contents($release, 'release');
            self::assertSame(0, proc_close($process));
        } finally {
            if (is_resource($process)) { file_put_contents($release, 'release'); proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        }
    }

    private function createUser(string $name): int
    {
        $this->db()->prepare('INSERT INTO users (username,email,password_hash,status,auth_version,created_at,updated_at) VALUES (:u,:e,:p,"active",1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['u'=>$name,'e'=>$name.'@example.test','p'=>password_hash('unused', PASSWORD_DEFAULT)]);
        return (int) $this->db()->lastInsertId();
    }
    private function grantSuperAdministrator(int $userId): void
    {
        $this->grantRole($userId, 'super-administrator');
    }
    private function grantRole(int $userId, string $role): void { $this->db()->prepare('INSERT INTO user_roles (user_id,role_id,created_at) SELECT :u,id,UTC_TIMESTAMP() FROM roles WHERE slug=:role')->execute(['u'=>$userId,'role'=>$role]); }
    private function loginAs(int $userId): void { $this->session->put('_auth_user_id', $userId); $this->session->put('_auth_version', 1); }
    private function request(string $method, string $path, array $input): Request { return new Request($method, $path, [], $input, [], [], ['REMOTE_ADDR'=>'127.0.0.1']); }
    private function removeTree(string $root): void { foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); @rmdir($root); }
}
