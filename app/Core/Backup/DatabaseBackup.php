<?php

declare(strict_types=1);

namespace NovaNuke\Core\Backup;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseBackup
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $directory,
    ) {
    }

    public function create(?string $backupSetId = null): string
    {
        $backupSetId = BackupSetId::normalize($backupSetId);
        if (is_link($this->directory)) throw new RuntimeException('Private backup directory must not be a symbolic link.');
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the private backup directory.');
        }
        if (! is_writable($this->directory)) throw new RuntimeException('The private backup directory is not writable.');

        $suffix = bin2hex(random_bytes(4));
        $filename = 'novanuke-db-' . gmdate('Ymd-His') . "-{$suffix}.sql";
        $finalPath = $this->directory . '/' . $filename;
        $temporaryPath = $finalPath . '.part';
        $stream = fopen($temporaryPath, 'xb');
        if ($stream === false) throw new RuntimeException('Unable to create the database backup.');

        $transactionStarted = false;
        try {
            chmod($temporaryPath, 0600);
            $engines = $this->tableEngines();
            $nonTransactional = array_keys(array_filter($engines, static fn (string $engine): bool => strcasecmp($engine, 'InnoDB') !== 0));

            $this->database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->database->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $transactionStarted = true;

            $snapshot = $nonTransactional === [] ? 'consistent-inno-db' : 'mixed-engines';
            $this->write($stream, "-- NovaNuke database backup\n");
            $this->write($stream, '-- Created: ' . gmdate(DATE_ATOM) . "\n");
            $this->write($stream, "-- Format: 2\n");
            $this->write($stream, "-- Backup-Set: {$backupSetId}\n");
            $this->write($stream, "-- Snapshot: {$snapshot}\n");
            if ($nonTransactional !== []) {
                $this->write($stream, '-- Non-Transactional-Tables: ' . implode(',', $nonTransactional) . "\n");
            }
            $this->write($stream, "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $this->database->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as $record) {
                $table = (string) $record[0];
                $identifier = $this->identifier($table);
                $create = $this->database->query("SHOW CREATE TABLE {$identifier}")->fetch(PDO::FETCH_NUM);
                if (! is_array($create) || ! isset($create[1])) throw new RuntimeException("Unable to inspect table: {$table}");

                $this->write($stream, "DROP TABLE IF EXISTS {$identifier};\n{$create[1]};\n");
                $rows = $this->database->query("SELECT * FROM {$identifier}");
                while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $columns = implode(', ', array_map($this->identifier(...), array_keys($row)));
                    $values = implode(', ', array_map(fn (mixed $value): string => $this->literal($value), array_values($row)));
                    $this->write($stream, "INSERT INTO {$identifier} ({$columns}) VALUES ({$values});\n");
                }
                $this->write($stream, "\n");
            }
            $this->write($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (! fflush($stream)) throw new RuntimeException('Unable to flush the database backup.');

            $this->database->exec('COMMIT');
            $transactionStarted = false;
            fclose($stream);
            $stream = null;
            if (! rename($temporaryPath, $finalPath)) throw new RuntimeException('Unable to finalize the database backup.');
            chmod($finalPath, 0600);
            return $finalPath;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                try { $this->database->exec('ROLLBACK'); } catch (Throwable) {}
            }
            if (is_resource($stream)) fclose($stream);
            if (is_file($temporaryPath)) unlink($temporaryPath);
            throw $error;
        }
    }

    /** @return array<string,string> */
    private function tableEngines(): array
    {
        $engines = [];
        $rows = $this->database->query("SHOW TABLE STATUS WHERE Engine IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = (string) ($row['Name'] ?? '');
            $engine = (string) ($row['Engine'] ?? '');
            if ($name !== '' && $engine !== '') $engines[$name] = $engine;
        }
        return $engines;
    }

    private function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function literal(mixed $value): string
    {
        if ($value === null) return 'NULL';
        $quoted = $this->database->quote((string) $value);
        if ($quoted === false) throw new RuntimeException('Unable to encode a database value.');
        return $quoted;
    }

    /** @param resource $stream */
    private function write($stream, string $content): void
    {
        if (fwrite($stream, $content) === false) throw new RuntimeException('Unable to write the database backup.');
    }
}
