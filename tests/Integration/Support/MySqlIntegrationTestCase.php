<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Integration\Support;

use NovaNuke\Core\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

abstract class MySqlIntegrationTestCase extends TestCase
{
    private const DATABASE_PATTERN = '/^novanuke_test_[a-f0-9]{16}$/';

    private ?PDO $database = null;
    private ?string $databaseName = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ((string) env('NOVANUKE_RUN_INTEGRATION', '') !== '1') {
            self::markTestSkipped('Set NOVANUKE_RUN_INTEGRATION=1 to run this isolated MySQL integration test.');
        }

        if (! extension_loaded('pdo_mysql')) {
            self::fail('The pdo_mysql extension is required for MySQL integration tests.');
        }

        $host = (string) env('NOVANUKE_TEST_DB_HOST', '127.0.0.1');
        $port = filter_var(env('NOVANUKE_TEST_DB_PORT', '3306'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            self::fail('NOVANUKE_TEST_DB_PORT is invalid.');
        }

        $username = (string) env('NOVANUKE_TEST_DB_USERNAME', 'root');
        $password = (string) env('NOVANUKE_TEST_DB_PASSWORD', '');
        $this->databaseName = 'novanuke_test_' . bin2hex(random_bytes(8));
        $this->assertSafeDatabaseName($this->databaseName);

        foreach (['DB_DATABASE', 'NOVANUKE_DB_DATABASE', 'DATABASE_NAME'] as $key) {
            if ((string) env($key, '') === $this->databaseName) {
                throw new RuntimeException('Refusing to use the configured application database as an integration database.');
            }
        }

        $server = null;
        try {
            $server = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            $quotedName = $this->quoteIdentifier($this->databaseName);
            $server->exec("CREATE DATABASE {$quotedName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $this->database = new PDO(
                "mysql:host={$host};port={$port};dbname={$this->databaseName};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );

            $this->migrateIntegrationDatabase($this->database);
        } catch (Throwable $error) {
            $this->database = null;
            $this->dropTemporaryDatabase($server, $this->databaseName);
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $this->database = null;
        $this->dropTemporaryDatabase(null, $this->databaseName);
        $this->databaseName = null;

        parent::tearDown();
    }

    protected function db(): PDO
    {
        if ($this->database === null) {
            throw new RuntimeException('The integration database is not available.');
        }

        return $this->database;
    }

    protected function integrationDatabaseName(): string
    {
        if ($this->databaseName === null) {
            throw new RuntimeException('The integration database is not available.');
        }

        return $this->databaseName;
    }

    protected function migrateIntegrationDatabase(PDO $database): void
    {
        (new Migrator($database))->run(dirname(__DIR__, 3) . '/database/migrations');
    }

    private function dropTemporaryDatabase(?PDO $server, ?string $databaseName): void
    {
        if ($databaseName === null || ! preg_match(self::DATABASE_PATTERN, $databaseName)) {
            return;
        }

        try {
            if ($server === null) {
                $host = (string) env('NOVANUKE_TEST_DB_HOST', '127.0.0.1');
                $port = filter_var(env('NOVANUKE_TEST_DB_PORT', '3306'), FILTER_VALIDATE_INT);
                if ($port === false) {
                    return;
                }
                $server = new PDO(
                    "mysql:host={$host};port={$port};charset=utf8mb4",
                    (string) env('NOVANUKE_TEST_DB_USERNAME', 'root'),
                    (string) env('NOVANUKE_TEST_DB_PASSWORD', ''),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
            }

            $server->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($databaseName));
        } catch (Throwable $error) {
            fwrite(STDERR, "Integration database cleanup failed for {$databaseName}: {$error->getMessage()}" . PHP_EOL);
        }
    }

    private function assertSafeDatabaseName(string $databaseName): void
    {
        if (! preg_match(self::DATABASE_PATTERN, $databaseName)) {
            throw new RuntimeException('Refusing to use an unsafe integration database name.');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        $this->assertSafeDatabaseName($identifier);

        return '`' . $identifier . '`';
    }
}
