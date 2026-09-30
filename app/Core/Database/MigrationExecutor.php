<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class MigrationExecutor
{
    /** @param null|Closure(string,string,string):void $faultInjector */
    public function __construct(
        private readonly PDO $database,
        private readonly MigrationOperationStore $operations,
        private readonly ?Closure $faultInjector = null,
    ) {
    }

    /** @param Closure():void $recordCompletion */
    public function apply(string $scope, string $name, string $file, Migration $migration, Closure $recordCompletion): void
    {
        $this->execute('up', $scope, $name, $file, $migration, $recordCompletion);
    }

    /** @param Closure():void $removeCompletion */
    public function rollBack(string $scope, string $name, string $file, Migration $migration, Closure $removeCompletion): void
    {
        $this->execute('down', $scope, $name, $file, $migration, $removeCompletion);
    }

    /** @param Closure():void $finalize */
    private function execute(string $direction, string $scope, string $name, string $file, Migration $migration, Closure $finalize): void
    {
        if ($this->database->inTransaction()) {
            throw new RuntimeException('Migrations must start outside a database transaction so their recovery marker is durable.');
        }
        $checksum = hash_file('sha256', $file);
        if (! is_string($checksum)) throw new RuntimeException("Unable to fingerprint migration: {$file}");
        $previous = $this->operations->find($scope, $name, $direction);
        if (is_array($previous) && ! hash_equals((string) $previous['file_checksum'], $checksum)) {
            throw new RuntimeException("Migration changed after an interrupted attempt: {$scope}.{$name}");
        }

        $recovering = is_array($previous) && in_array((string) $previous['state'], ['running', 'dirty'], true);
        if ($recovering && ! $migration instanceof RecoverableMigration) {
            throw new RuntimeException(
                "Interrupted legacy migration requires a recoverable implementation before retry: {$scope}.{$name}. "
                . 'Do not edit migration tables or retry blindly.'
            );
        }

        $this->operations->start($scope, $name, $direction, $checksum);
        try {
            if ($this->faultInjector !== null) {
                ($this->faultInjector)('before_' . $direction, $scope, $name);
            }
            $alreadyFinal = $migration instanceof RecoverableMigration
                && ($direction === 'up' ? $migration->isApplied($this->database) : $migration->isRolledBack($this->database));
            if (! $alreadyFinal) {
                $direction === 'up' ? $migration->up($this->database) : $migration->down($this->database);
            }

            if ($this->faultInjector !== null) {
                ($this->faultInjector)('after_' . $direction, $scope, $name);
            }

            if ($migration instanceof RecoverableMigration) {
                $verified = $direction === 'up'
                    ? $migration->isApplied($this->database)
                    : $migration->isRolledBack($this->database);
                if (! $verified) throw new RuntimeException("Migration postcondition failed: {$scope}.{$name} ({$direction}).");
            }

            $this->database->beginTransaction();
            try {
                $finalize();
                $this->operations->markCompleted($scope, $name, $direction);
                $this->database->commit();
            } catch (Throwable $error) {
                if ($this->database->inTransaction()) $this->database->rollBack();
                throw $error;
            }
        } catch (MigrationInterruption $error) {
            // Model an abrupt process death: leave the durable marker running.
            throw $error;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            try {
                $this->operations->markDirty($scope, $name, $direction, $error->getMessage());
            } catch (Throwable) {
                // The existing running marker remains useful if the connection died.
            }
            throw $error;
        }
    }
}
