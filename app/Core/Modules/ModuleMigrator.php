<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use NovaNuke\Core\Database\Migration;
use NovaNuke\Core\Database\MigrationFileSet;
use NovaNuke\Core\Database\MigrationExecutor;
use NovaNuke\Core\Database\MigrationLock;
use NovaNuke\Core\Database\MigrationOperationStore;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class ModuleMigrator
{
    private readonly MigrationOperationStore $operations;

    /** @param null|Closure(string,string,string):void $faultInjector */
    public function __construct(private readonly PDO $database, private readonly ?Closure $faultInjector = null)
    {
        $this->operations = new MigrationOperationStore($database);
    }

    /** @return list<string> */
    public function run(ModuleManifest $manifest): array
    {
        return $this->execute($manifest, false);
    }

    /** @return list<string> */
    public function recover(ModuleManifest $manifest): array
    {
        $recoveredDown = $this->recoverInterruptedRollback($manifest);
        if ($recoveredDown !== []) return $recoveredDown;
        return $this->execute($manifest, true);
    }

    /** @return list<string> */
    private function recoverInterruptedRollback(ModuleManifest $manifest): array
    {
        $lock = new MigrationLock($this->database);
        $lock->acquire();
        try {
            $this->operations->ensureRepository();
            $scope = 'module:' . $manifest->slug;
            $pending = array_values(array_filter(
                $this->operations->unresolved($scope),
                static fn (array $operation): bool => $operation['direction'] === 'down',
            ));
            if ($pending === []) return [];

            $byName = [];
            foreach ($pending as $operation) $byName[(string) $operation['migration']] = true;
            $statement = $this->database->prepare(
                'SELECT migration FROM module_migrations WHERE module_slug=:slug ORDER BY id DESC'
            );
            $statement->execute(['slug' => $manifest->slug]);
            $executor = new MigrationExecutor($this->database, $this->operations, $this->faultInjector);
            $completed = [];
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $name = (string) $name;
                if (! isset($byName[$name])) continue;
                $file = $manifest->path . '/database/migrations/' . basename($name) . '.php';
                if (! is_file($file)) throw new RuntimeException("Cannot recover missing module migration: {$name}");
                $migration = require $file;
                if (! $migration instanceof Migration) throw new RuntimeException("Module migration must implement Migration: {$file}");
                $executor->rollBack($scope, $name, $file, $migration, function () use ($manifest, $name): void {
                    $delete = $this->database->prepare(
                        'DELETE FROM module_migrations WHERE module_slug=:slug AND migration=:migration'
                    );
                    $delete->execute(['slug' => $manifest->slug, 'migration' => $name]);
                });
                $completed[] = $name;
            }
            if (count($completed) !== count($pending)) {
                throw new RuntimeException("Cannot reconcile every interrupted rollback for module {$manifest->slug}.");
            }
            return $completed;
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> */
    private function execute(ModuleManifest $manifest, bool $onlyRecovering): array
    {
        $lock = new MigrationLock($this->database);
        $lock->acquire();
        try {
            $this->operations->ensureRepository();
            if (! $onlyRecovering) $this->operations->assertNoneUnresolved("run module {$manifest->slug} migrations");
            $directory = $manifest->path . '/database/migrations';
            $status = $this->status($manifest);
            if ($status['missing_files'] !== []) {
                throw new RuntimeException(
                    "Cannot update module {$manifest->slug} while executed migration files are missing: "
                    . implode(', ', $status['missing_files'])
                );
            }
            $files = is_dir($directory) ? (glob($directory . '/*.php') ?: []) : [];
            sort($files, SORT_STRING);
            $availableFiles = array_fill_keys(array_map(static fn (string $file): string => basename($file, '.php'), $files), true);
            foreach ($this->operations->unresolved('module:' . $manifest->slug) as $operation) {
                if ($operation['direction'] === 'up' && ! isset($availableFiles[(string) $operation['migration']])) {
                    throw new RuntimeException(
                        "Cannot recover interrupted module migration because its source file is missing: {$manifest->slug}.{$operation['migration']}"
                    );
                }
            }
            $executed = $this->executed($manifest->slug);
            $batch = $this->nextBatch($manifest->slug);
            $completed = [];
            $scope = 'module:' . $manifest->slug;
            $executor = new MigrationExecutor($this->database, $this->operations, $this->faultInjector);

            foreach ($files as $file) {
                $name = basename($file, '.php');
                if (isset($executed[$name])) {
                    continue;
                }
                $operation = $this->operations->find($scope, $name, 'up');
                if ($onlyRecovering && (! is_array($operation) || ! in_array((string) $operation['state'], ['running', 'dirty'], true))) continue;
                try {
                    $migration = require $file;
                    if (! $migration instanceof Migration) {
                        throw new RuntimeException("Module migration must implement Migration: {$file}");
                    }
                    $executor->apply($scope, $name, $file, $migration, function () use ($manifest, $name, $batch): void {
                        $statement = $this->database->prepare(
                            'INSERT INTO module_migrations (module_slug,migration,batch,executed_at) '
                            . 'VALUES (:module_slug,:migration,:batch,UTC_TIMESTAMP())'
                        );
                        $statement->execute(['module_slug' => $manifest->slug, 'migration' => $name, 'batch' => $batch]);
                    });
                    $completed[] = $name;
                    $executed[$name] = true;
                } catch (Throwable $error) {
                    throw new RuntimeException(
                        "Module migration failed: {$manifest->slug}.{$name}. No later migration was run.",
                        0,
                        $error,
                    );
                }
            }

            return $completed;
        } finally {
            $lock->release();
        }
    }

    public function rollbackAll(ModuleManifest $manifest): void
    {
        $lock = new MigrationLock($this->database);
        $lock->acquire();
        try {
            $this->operations->ensureRepository();
            $this->operations->assertNoneUnresolved("uninstall module {$manifest->slug}");
            $statement = $this->database->prepare(
                'SELECT migration FROM module_migrations WHERE module_slug = :slug ORDER BY id DESC'
            );
            $statement->execute(['slug' => $manifest->slug]);
            $scope = 'module:' . $manifest->slug;
            $executor = new MigrationExecutor($this->database, $this->operations, $this->faultInjector);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $file = $manifest->path . '/database/migrations/' . basename((string) $name) . '.php';
                if (! is_file($file)) {
                    throw new RuntimeException("Cannot roll back missing module migration: {$name}");
                }
                $migration = require $file;
                if (! $migration instanceof Migration) {
                    throw new RuntimeException("Module migration must implement Migration: {$file}");
                }
                $executor->rollBack($scope, (string) $name, $file, $migration, function () use ($manifest, $name): void {
                    $delete = $this->database->prepare(
                        'DELETE FROM module_migrations WHERE module_slug=:slug AND migration=:migration'
                    );
                    $delete->execute(['slug' => $manifest->slug, 'migration' => $name]);
                });
            }
        } finally {
            $lock->release();
        }
    }

    /** @return array{total:int,executed:int,pending:list<string>,missing_files:list<string>,recovery:list<array<string,mixed>>} */
    public function status(ModuleManifest $manifest): array
    {
        $executed = [];
        if ($this->repositoryAvailable()) {
            $statement = $this->database->prepare(
                'SELECT migration FROM module_migrations WHERE module_slug = :slug ORDER BY migration'
            );
            $statement->execute(['slug' => $manifest->slug]);
            $executed = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        }

        return [
            ...(new MigrationFileSet())->compare($manifest->path . '/database/migrations', $executed),
            'recovery' => $this->operations->available()
                ? $this->operations->unresolved('module:' . $manifest->slug)
                : [],
        ];
    }

    /** @return array<string, true> */
    private function executed(string $slug): array
    {
        $statement = $this->database->prepare('SELECT migration FROM module_migrations WHERE module_slug = :slug');
        $statement->execute(['slug' => $slug]);

        return array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
    }

    private function nextBatch(string $slug): int
    {
        $statement = $this->database->prepare(
            'SELECT COALESCE(MAX(batch), 0) FROM module_migrations WHERE module_slug = :slug'
        );
        $statement->execute(['slug' => $slug]);

        return ((int) $statement->fetchColumn()) + 1;
    }

    private function repositoryAvailable(): bool
    {
        return (int) $this->database->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'module_migrations'"
        )->fetchColumn() === 1;
    }
}
