<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseRestoreVerifier
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{statements:int,tables:int,migrations:int} */
    public function verify(string $sqlPath): array
    {
        if (! is_file($sqlPath) || is_link($sqlPath) || ! is_readable($sqlPath)) {
            throw new RuntimeException('Database backup is not a regular readable file.');
        }
        $existing = (int) $this->database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
        if ($existing !== 0) {
            throw new RuntimeException('Disposable restore database must be empty before verification.');
        }

        $sql = file_get_contents($sqlPath);
        if (! is_string($sql) || $sql === '') throw new RuntimeException('Database backup SQL cannot be read.');
        $statements = $this->splitStatements($sql);
        if ($statements === []) throw new RuntimeException('Database backup contains no executable SQL.');

        try {
            foreach ($statements as $statement) $this->database->exec($statement);
            $tables = (int) $this->database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
            if ($tables < 1) throw new RuntimeException('Restored database contains no tables.');
            $hasMigrations = (int) $this->database->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name='migrations'")->fetchColumn();
            if ($hasMigrations !== 1) throw new RuntimeException('Restored database is missing the migrations table.');
            $migrations = (int) $this->database->query('SELECT COUNT(*) FROM `migrations`')->fetchColumn();
            return ['statements' => count($statements), 'tables' => $tables, 'migrations' => $migrations];
        } catch (Throwable $error) {
            throw new RuntimeException('Disposable SQL restore failed: ' . $error->getMessage(), 0, $error);
        } finally {
            $this->emptyDatabase();
        }
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($escaped) { $escaped = false; continue; }
                if ($char === '\\' && $quote !== '`') { $escaped = true; continue; }
                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote && $quote !== '`') {
                        $buffer .= $sql[++$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                $buffer .= "\n";
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') $statements[] = $statement;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if ($quote !== null) throw new RuntimeException('Database backup contains an unterminated quoted value.');
        if (trim($buffer) !== '') throw new RuntimeException('Database backup contains a trailing unterminated SQL statement.');
        return $statements;
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
