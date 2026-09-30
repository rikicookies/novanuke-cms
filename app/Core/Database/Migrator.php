<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    private readonly MigrationOperationStore $operations;

    /** @param null|Closure(string,string,string):void $faultInjector */
    public function __construct(private readonly PDO $database, private readonly ?Closure $faultInjector = null)
    {
        $this->operations = new MigrationOperationStore($database);
    }

    public function ensureRepository(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'migration VARCHAR(255) NOT NULL UNIQUE,'
            . 'batch INT UNSIGNED NOT NULL,'
            . 'executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->operations->ensureRepository();
    }

    /** @return list<string> */
    public function run(string $directory): array
    {
        return $this->execute($directory, false);
    }

    /** Reconcile only operations left running/dirty by an earlier attempt. @return list<string> */
    public function recover(string $directory): array
    {
        return $this->execute($directory, true);
    }

    /** @return list<array<string,mixed>> */
    public function unresolvedOperations(?string $scope = null): array
    {
        return $this->operations->available() ? $this->operations->unresolved($scope) : [];
    }

    /** @return array{total:int,executed:int,pending:list<string>,missing_files:list<string>,recovery:list<array<string,mixed>>} */
    public function status(string $directory): array
    {
        $executed = $this->repositoryAvailable()
            ? $this->database->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)
            : [];

        return [
            ...(new MigrationFileSet())->compare($directory, array_map('strval', $executed)),
            'recovery' => $this->operations->available() ? $this->operations->unresolved('core') : [],
        ];
    }

    /** @return list<string> */
    private function execute(string $directory, bool $onlyRecovering): array
    {
        $lock = new MigrationLock($this->database);
        $lock->acquire();
        try {
            $this->ensureRepository();
            if (! $onlyRecovering) $this->operations->assertNoneUnresolved('run migrations');
            $status = $this->status($directory);
            if ($status['missing_files'] !== []) {
                throw new RuntimeException(
                    'Cannot run core migrations while executed migration files are missing: '
                    . implode(', ', $status['missing_files'])
                );
            }
            $executed = $this->executed();
            $files = glob(rtrim($directory, '/') . '/*.php') ?: [];
            sort($files, SORT_STRING);
            $availableFiles = array_fill_keys(array_map(static fn (string $file): string => basename($file, '.php'), $files), true);
            foreach ($this->operations->unresolved('core') as $operation) {
                if ($operation['direction'] === 'up' && ! isset($availableFiles[(string) $operation['migration']])) {
                    throw new RuntimeException('Cannot recover interrupted Core migration because its source file is missing: ' . $operation['migration']);
                }
            }
            $batch = $this->nextBatch();
            $completed = [];
            $executor = new MigrationExecutor($this->database, $this->operations, $this->faultInjector);

            foreach ($files as $file) {
                $name = basename($file, '.php');
                if (isset($executed[$name])) continue;
                $operation = $this->operations->find('core', $name, 'up');
                if ($onlyRecovering && (! is_array($operation) || ! in_array((string) $operation['state'], ['running', 'dirty'], true))) {
                    continue;
                }
                try {
                    $migration = require $file;
                    if (! $migration instanceof Migration) throw new RuntimeException("Migration must implement Migration: {$file}");
                    $executor->apply('core', $name, $file, $migration, function () use ($name, $batch): void {
                        $statement = $this->database->prepare('INSERT INTO migrations (migration,batch) VALUES (:migration,:batch)');
                        $statement->execute(['migration' => $name, 'batch' => $batch]);
                    });
                    $completed[] = $name;
                    $executed[$name] = true;
                } catch (Throwable $error) {
                    throw new RuntimeException("Core migration failed: {$name}. No later migration was run.", 0, $error);
                }
            }
            return $completed;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, true> */
    private function executed(): array
    {
        $names = $this->database->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        return array_fill_keys($names, true);
    }

    private function repositoryAvailable(): bool
    {
        return (int) $this->database->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'"
        )->fetchColumn() === 1;
    }

    private function nextBatch(): int
    {
        $current = $this->database->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();

        return ((int) $current) + 1;
    }
}
