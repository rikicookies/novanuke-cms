<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseRestoreVerifier
{
    public function __construct(
        private readonly PDO $database,
        private readonly int $maxStatementBytes = DatabaseBackupStatementReader::DEFAULT_MAX_STATEMENT_BYTES,
    )
    {
    }

    /** @return array{statements:int,tables:int,migrations:int,row_counts:array<string,int>} */
    public function verify(string $sqlPath): array
    {
        if (! is_file($sqlPath) || is_link($sqlPath) || ! is_readable($sqlPath)) {
            throw new RuntimeException('Database backup is not a regular readable file.');
        }
        $existing = (int) $this->database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
        if ($existing !== 0) {
            throw new RuntimeException('Disposable restore database must be empty before verification.');
        }

        $statementCount = 0;
        try {
            foreach ((new DatabaseBackupStatementReader($this->maxStatementBytes))->read($sqlPath) as $statement) {
                $this->database->exec($statement);
                $statementCount++;
            }
            $tables = (int) $this->database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
            if ($tables < 1) throw new RuntimeException('Restored database contains no tables.');
            $hasMigrations = (int) $this->database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name='migrations'")->fetchColumn();
            if ($hasMigrations !== 1) throw new RuntimeException('Restored database is missing the migrations table.');
            $migrations = (int) $this->database->query('SELECT COUNT(*) FROM `migrations`')->fetchColumn();
            $rowCounts = [];
            foreach ($this->database->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
                $rowCounts[(string) $table] = (int) $this->database->query("SELECT COUNT(*) FROM {$identifier}")->fetchColumn();
            }
            return ['statements' => $statementCount, 'tables' => $tables, 'migrations' => $migrations, 'row_counts' => $rowCounts];
        } catch (Throwable $error) {
            throw new RuntimeException('Disposable SQL restore failed: ' . $error->getMessage(), 0, $error);
        } finally {
            $this->emptyDatabase();
        }
    }

    private function emptyDatabase(): void
    {
        try {
            $this->database->exec('SET FOREIGN_KEY_CHECKS=0');
            $tables = $this->database->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
                $this->database->exec("DROP TABLE IF EXISTS {$identifier}");
            }
        } finally {
            try { $this->database->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable) {}
        }
    }
}
