<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Tests\Integration\Support\MySqlIntegrationTestCase;

final class BackupManagerKernelAcceptanceTest extends MySqlIntegrationTestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir() . '/novanuke-backup-kernel-' . bin2hex(random_bytes(6));
        foreach (['modules', 'themes', 'public/uploads', 'storage/private/avatars', 'storage/private/downloads', 'storage/private/wiki', 'storage/private/backups'] as $path) {
            mkdir($this->fixtureRoot . '/' . $path, 0700, true);
        }
        file_put_contents($this->fixtureRoot . '/modules/kernel-fixture.txt', 'fixture');
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            $this->removeTree($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function testRealApplicationKernelRendersEmptyAndExistingBackupLists(): void
    {
        $admin = $this->userWithRole('super-administrator');
        $empty = $this->runKernelRequest($admin, $this->fixtureRoot . '/storage/private/backups');
        self::assertSame(200, $empty['status'], $empty['content']);
        self::assertStringContainsString('No backup sets', $empty['content']);

        $set = (new BackupSetCoordinator($this->db(), $this->fixtureRoot, $this->fixtureRoot . '/storage/private/backups'))->create();
        $existing = $this->runKernelRequest($admin, $this->fixtureRoot . '/storage/private/backups');
        self::assertSame(200, $existing['status'], $existing['content']);
        self::assertStringContainsString($set['backup_set_id'], $existing['content']);
        self::assertStringContainsString('Verified', $existing['content']);
        self::assertStringNotContainsString($this->fixtureRoot, $existing['content']);
    }

    public function testKernelHandlesMissingAndNonDirectoryBackupStorage(): void
    {
        $admin = $this->userWithRole('super-administrator');
        $missing = $this->runKernelRequest($admin, $this->fixtureRoot . '/storage/private/missing-backups');
        self::assertSame(200, $missing['status'], $missing['content']);
        self::assertStringContainsString('No backup sets', $missing['content']);

        $notDirectory = $this->fixtureRoot . '/storage/private/not-a-directory';
        file_put_contents($notDirectory, 'not a directory');
        $response = $this->runKernelRequest($admin, $notDirectory);
        self::assertSame(200, $response['status'], $response['content']);
        self::assertStringNotContainsString($this->fixtureRoot, $response['content']);
    }

    public function testKernelKeepsMemberAndNormalAdministratorDenied(): void
    {
        $member = $this->userWithRole('member');
        self::assertSame(403, $this->runKernelRequest($member, $this->fixtureRoot . '/storage/private/backups')['status']);

        $administrator = $this->userWithRole('administrator');
        self::assertSame(403, $this->runKernelRequest($administrator, $this->fixtureRoot . '/storage/private/backups')['status']);
    }

    /**
     * @return array{status:int,content:string}
     */
    private function runKernelRequest(int $userId, string $statusDirectory): array
    {
        $script = $this->fixtureRoot . '/kernel-request.php';
        $scriptBody = <<<'PHP'
<?php
declare(strict_types=1);

require $argv[1];

$pdo = new PDO($argv[2], $argv[3], $argv[4], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$app = \NovaNuke\Core\Application::create($argv[5]);
$app->container()->instance(PDO::class, $pdo);
$session = new \NovaNuke\Core\Security\SessionManager('novanuke_kernel_acceptance_' . bin2hex(random_bytes(3)), false);
$app->container()->instance(\NovaNuke\Core\Security\SessionManager::class, $session);
$app->container()->instance(\NovaNuke\Core\Backup\BackupSetStatus::class, new \NovaNuke\Core\Backup\BackupSetStatus($argv[6]));
$_SESSION = ['_auth_user_id' => (int) $argv[7], '_auth_version' => 1];
$session->put('_auth_user_id', (int) $argv[7]);
$session->put('_auth_version', 1);
$response = $app->kernel()->handle(new \NovaNuke\Core\Http\Request('GET', '/admin/backups'));
echo $response->status(), PHP_EOL, base64_encode($response->content());
PHP
;
        file_put_contents($script, $scriptBody);

        $root = dirname(__DIR__, 2);
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string) env('NOVANUKE_TEST_DB_HOST', '127.0.0.1'),
            (string) env('NOVANUKE_TEST_DB_PORT', '3306'),
            $this->integrationDatabaseName(),
        );
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            $script,
            $root . '/vendor/autoload.php',
            $dsn,
            (string) env('NOVANUKE_TEST_DB_USERNAME', 'root'),
            (string) env('NOVANUKE_TEST_DB_PASSWORD', ''),
            $root,
            $statusDirectory,
            (string) $userId,
        ]));
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Could not start the application kernel child process.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($script);

        self::assertSame(0, $exitCode, trim($stderr . PHP_EOL . $stdout));
        $parts = explode(PHP_EOL, trim($stdout), 2);
        self::assertCount(2, $parts, trim($stderr . PHP_EOL . $stdout));

        return [
            'status' => (int) $parts[0],
            'content' => base64_decode($parts[1], true) ?: '',
        ];
    }

    private function userWithRole(string $role): int
    {
        $this->db()->prepare('INSERT INTO users (username,email,password_hash,status,auth_version,created_at,updated_at) VALUES (:u,:e,:p,"active",1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([
            'u' => 'backup-kernel-' . bin2hex(random_bytes(3)),
            'e' => bin2hex(random_bytes(4)) . '@example.test',
            'p' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $id = (int) $this->db()->lastInsertId();
        $this->db()->prepare('INSERT INTO user_roles (user_id,role_id,created_at) SELECT :user_id,id,UTC_TIMESTAMP() FROM roles WHERE slug=:role')->execute(['user_id' => $id, 'role' => $role]);
        return $id;
    }

    private function removeTree(string $root): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }
}
