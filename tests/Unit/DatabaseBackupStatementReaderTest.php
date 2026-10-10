<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Backup\DatabaseBackupStatementReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseBackupStatementReaderTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path)) @unlink($this->path);
    }

    public function testGeneratedGrammarPreservesMultilineUnicodeBinaryLikeTextAndSemicolons(): void
    {
        $sql = "-- NovaNuke database backup\nSET NAMES utf8mb4;\n"
            . "CREATE TABLE `fixture` (\n  `id` int NOT NULL,\n  `body` text\n) ENGINE=InnoDB;\n"
            . "INSERT INTO `fixture` (`id`, `body`) VALUES ('1', 'line one;\\'quoted\\'\\n雪\\\\bytes');\n"
            . "SET FOREIGN_KEY_CHECKS=1;\n";
        $statements = $this->read($sql);

        self::assertCount(4, $statements);
        self::assertStringContainsString("'line one;\\'quoted\\'\\n雪\\\\bytes'", $statements[2]);
        self::assertStringContainsString("CREATE TABLE `fixture` (\n", $statements[1]);
    }

    public function testUnsupportedStatementsAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported SQL syntax');
        $this->read("SET NAMES utf8mb4;\nUPDATE `fixture` SET `id`=2;\n");
    }

    public function testExternalMultiRowInsertSyntaxIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported SQL syntax');
        $this->read("INSERT INTO `fixture` (`id`) VALUES ('1'), ('2');\n");
    }

    public function testTruncatedAndMalformedInputFailsClosed(): void
    {
        foreach (["INSERT INTO `fixture` (`body`) VALUES ('unterminated);", "INSERT INTO `fixture` (`body`) VALUES ('bad\\');"] as $sql) {
            try {
                $this->read($sql);
                self::fail('Malformed SQL must be rejected.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Database backup contains', $error->getMessage());
            }
        }
    }

    public function testStatementLimitIsEnforcedWithoutLoadingWholeFile(): void
    {
        $statement = "INSERT INTO `fixture` (`body`) VALUES ('" . str_repeat('x', 2500) . "');\n";
        $this->path = tempnam(sys_get_temp_dir(), 'novanuke-sql-');
        file_put_contents($this->path, str_repeat($statement, 2000));
        $count = 0;
        try {
            foreach ((new DatabaseBackupStatementReader(2048))->read($this->path) as $_) $count++;
            self::fail('Oversized statements must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('exceeds the 2048-byte limit', $error->getMessage());
            self::assertSame(0, $count);
        }
    }

    public function testLargeGeneratedBackupIsProcessedIncrementally(): void
    {
        $statement = "INSERT INTO `fixture` (`body`) VALUES ('" . str_repeat('x', 2400) . "');\n";
        $this->path = tempnam(sys_get_temp_dir(), 'novanuke-sql-');
        file_put_contents($this->path, str_repeat($statement, 2200));
        $before = memory_get_usage(true);
        $count = 0;
        foreach ((new DatabaseBackupStatementReader())->read($this->path) as $_) $count++;
        $after = memory_get_usage(true);

        self::assertSame(2200, $count);
        self::assertLessThan(4 * 1024 * 1024, $after - $before, 'Reader memory should be bounded by statement size, not file size.');
    }

    /** @return list<string> */
    private function read(string $sql): array
    {
        $this->path = tempnam(sys_get_temp_dir(), 'novanuke-sql-');
        file_put_contents($this->path, $sql);
        $result = [];
        foreach ((new DatabaseBackupStatementReader())->read($this->path) as $statement) $result[] = $statement;
        return $result;
    }
}
