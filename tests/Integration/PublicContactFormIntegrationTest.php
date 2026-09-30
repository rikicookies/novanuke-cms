<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration;

use NovaNuke\Core\Database\Migrator;
use NovaNuke\Core\Forms\ContactFormController;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Mail\LogMailer;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Settings\SettingsRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PublicContactFormIntegrationTest extends TestCase
{
    private ?PDO $database = null;
    private ?PDO $server = null;
    private ?string $databaseName = null;
    private string $mailLog;

    protected function setUp(): void
    {
        if ((string) env('NOVANUKE_RUN_INTEGRATION', '') !== '1') {
            self::markTestSkipped('Set NOVANUKE_RUN_INTEGRATION=1 to run this isolated MySQL integration test.');
        }

        $host = (string) env('NOVANUKE_TEST_DB_HOST', '127.0.0.1');
        $port = filter_var(env('NOVANUKE_TEST_DB_PORT', '3306'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) self::fail('NOVANUKE_TEST_DB_PORT is invalid.');

        $this->databaseName = 'novanuke_test_' . bin2hex(random_bytes(8));
        if (! preg_match('/^novanuke_test_[a-f0-9]{16}$/', $this->databaseName)) {
            self::fail('Unsafe integration database name.');
        }
        foreach (['DB_DATABASE', 'NOVANUKE_DB_DATABASE', 'DATABASE_NAME'] as $key) {
            if ((string) env($key, '') === $this->databaseName) {
                self::fail('Refusing to use the configured application database as an integration database.');
            }
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->server = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            (string) env('NOVANUKE_TEST_DB_USERNAME', 'root'),
            (string) env('NOVANUKE_TEST_DB_PASSWORD', ''),
            $options,
        );
        $this->server->exec("CREATE DATABASE `{$this->databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $this->database = new PDO(
                "mysql:host={$host};port={$port};dbname={$this->databaseName};charset=utf8mb4",
                (string) env('NOVANUKE_TEST_DB_USERNAME', 'root'),
                (string) env('NOVANUKE_TEST_DB_PASSWORD', ''),
                $options,
            );
            (new Migrator($this->database))->run(dirname(__DIR__, 2) . '/database/migrations');
        } catch (Throwable $error) {
            $this->database = null;
            $this->server->exec("DROP DATABASE `{$this->databaseName}`");
            $this->server = null;
            $this->databaseName = null;
            throw $error;
        }

        $_SESSION = [];
        $this->mailLog = sys_get_temp_dir() . '/novanuke-contact-' . bin2hex(random_bytes(8)) . '.log';
    }

    protected function tearDown(): void
    {
        if (isset($this->mailLog) && is_file($this->mailLog)) @unlink($this->mailLog);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $_SESSION = [];
        $this->database = null;

        if ($this->server !== null && $this->databaseName !== null
            && preg_match('/^novanuke_test_[a-f0-9]{16}$/', $this->databaseName)) {
            try {
                $this->server->exec("DROP DATABASE `{$this->databaseName}`");
            } catch (Throwable $error) {
                fwrite(STDERR, "Unable to remove temporary Forms database {$this->databaseName}: {$error->getMessage()}\n");
            }
        }
        $this->server = null;
        $this->databaseName = null;
    }

    public function testValidSubmissionUsesLogMailerAndRedirectsToSent(): void
    {
        $settings = new SettingsRepository($this->db());
        $settings->setString('site.name', 'Local NovaNuke', 'site');
        $settings->setString('site.admin_email', 'admin@example.test', 'site');

        $csrf = new CsrfTokenManager(new SessionManager('novanuke_forms_test', false));
        $token = $csrf->token();
        $controller = $this->controller($csrf, $settings);
        $request = new Request('POST', '/forms/contact', [], [
            '_token' => $token,
            'return_to' => '/pages/contact',
            'website' => '',
            'name' => 'Local Tester',
            'email' => 'visitor@example.test',
            'phone' => '555-0100',
            'address' => '123 Test Ave',
            'subject' => 'Estimate',
            'message' => 'This message never leaves Laragon.',
        ], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $response = $controller->submit($request);

        self::assertSame(303, $response->status());
        self::assertSame('/pages/contact?form=sent', $response->header('Location'));
        self::assertFileExists($this->mailLog);
        $log = (string) file_get_contents($this->mailLog);
        self::assertStringContainsString('To: admin@example.test', $log);
        self::assertStringContainsString('Reply-To: visitor@example.test', $log);
        self::assertStringContainsString('Subject: Website inquiry — Local NovaNuke: Estimate', $log);
        self::assertStringContainsString('Name: Local Tester', $log);
        self::assertStringContainsString('Message: This message never leaves Laragon.', $log);
        self::assertStringContainsString('IP: 127.0.0.1', $log);
    }

    public function testInvalidSubmissionDoesNotWriteMail(): void
    {
        $settings = new SettingsRepository($this->db());
        $settings->setString('site.admin_email', 'admin@example.test', 'site');
        $csrf = new CsrfTokenManager(new SessionManager('novanuke_forms_test', false));
        $controller = $this->controller($csrf, $settings);

        $response = $controller->submit(new Request('POST', '/forms/contact', [], [
            '_token' => $csrf->token(),
            'return_to' => '/pages/contact',
            'name' => 'Local Tester',
            'message' => '',
        ], [], [], ['REMOTE_ADDR' => '127.0.0.2']));

        self::assertSame(303, $response->status());
        self::assertSame('/pages/contact?form=invalid', $response->header('Location'));
        self::assertFileDoesNotExist($this->mailLog);
    }

    public function testInvalidCsrfDoesNotWriteMail(): void
    {
        $settings = new SettingsRepository($this->db());
        $settings->setString('site.admin_email', 'admin@example.test', 'site');
        $csrf = new CsrfTokenManager(new SessionManager('novanuke_forms_test', false));
        $csrf->token();
        $controller = $this->controller($csrf, $settings);

        $response = $controller->submit(new Request('POST', '/forms/contact', [], [
            '_token' => str_repeat('a', 64),
            'return_to' => '/pages/contact',
            'name' => 'Local Tester',
            'message' => 'Should not send.',
        ], [], [], ['REMOTE_ADDR' => '127.0.0.3']));

        self::assertSame(303, $response->status());
        self::assertSame('/pages/contact?form=invalid', $response->header('Location'));
        self::assertFileDoesNotExist($this->mailLog);
    }

    private function controller(CsrfTokenManager $csrf, SettingsRepository $settings): ContactFormController
    {
        return new ContactFormController(
            $csrf,
            new LogMailer($this->mailLog, 'local', 'noreply@example.test', 'NovaNuke'),
            $settings,
            $this->db(),
        );
    }

    private function db(): PDO
    {
        return $this->database ?? throw new \LogicException('Forms integration database is not available.');
    }
}
