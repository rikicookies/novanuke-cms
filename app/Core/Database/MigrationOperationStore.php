<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use PDO;
use RuntimeException;

final class MigrationOperationStore
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function ensureRepository(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS migration_operations ('
            . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
            . 'scope VARCHAR(120) NOT NULL,'
            . 'migration VARCHAR(255) NOT NULL,'
            . 'direction VARCHAR(8) NOT NULL,'
            . 'state VARCHAR(16) NOT NULL,'
            . 'file_checksum CHAR(64) NOT NULL,'
            . 'attempts INT UNSIGNED NOT NULL DEFAULT 1,'
            . 'error_message VARCHAR(1000) NULL,'
            . 'started_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'completed_at DATETIME NULL,'
            . 'UNIQUE KEY migration_operations_identity_unique (scope,migration,direction),'
            . 'KEY migration_operations_state_index (state,updated_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function available(): bool
    {
        return MigrationSchema::tableExists($this->database, 'migration_operations');
    }

    /** @return array<string,mixed>|null */
    public function find(string $scope, string $migration, string $direction): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM migration_operations WHERE scope=:scope AND migration=:migration AND direction=:direction LIMIT 1'
        );
        $statement->execute(compact('scope', 'migration', 'direction'));
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function unresolved(?string $scope = null): array
    {
        $sql = "SELECT scope,migration,direction,state,file_checksum,attempts,error_message,started_at,updated_at FROM migration_operations WHERE state IN ('running','dirty')";
        $parameters = [];
        if ($scope !== null) {
            $sql .= ' AND scope=:scope';
            $parameters['scope'] = $scope;
        }
        $sql .= ' ORDER BY scope,migration,direction';
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function assertNoneUnresolved(string $action): void
    {
        $operation = $this->unresolved()[0] ?? null;
        if (! is_array($operation)) return;
        $scope = (string) $operation['scope'];
        $recoveryCommand = str_starts_with($scope, 'module:')
            ? 'php bin/cms migrate:recover --module=' . substr($scope, strlen('module:'))
            : 'php bin/cms migrate:recover';
        throw new RuntimeException(sprintf(
            'Cannot %s while migration %s.%s (%s) is %s. Run %s first.',
            $action,
            $scope,
            (string) $operation['migration'],
            (string) $operation['direction'],
            strtoupper((string) $operation['state']),
            $recoveryCommand,
        ));
    }

    public function start(string $scope, string $migration, string $direction, string $checksum): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO migration_operations '
            . '(scope,migration,direction,state,file_checksum,attempts,error_message,started_at,updated_at,completed_at) '
            . "VALUES (:scope,:migration,:direction,'running',:checksum,1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL) "
            . "ON DUPLICATE KEY UPDATE state='running',attempts=attempts+1,error_message=NULL,"
            . 'started_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),completed_at=NULL'
        );
        $statement->execute(compact('scope', 'migration', 'direction', 'checksum'));
    }

    public function markDirty(string $scope, string $migration, string $direction, string $message): void
    {
        $message = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? 'Migration failed.', 0, 1000);
        $statement = $this->database->prepare(
            "UPDATE migration_operations SET state='dirty',error_message=:message,updated_at=UTC_TIMESTAMP(),completed_at=NULL "
            . 'WHERE scope=:scope AND migration=:migration AND direction=:direction'
        );
        $statement->execute(compact('message', 'scope', 'migration', 'direction'));
    }

    public function markCompleted(string $scope, string $migration, string $direction): void
    {
        $statement = $this->database->prepare(
            "UPDATE migration_operations SET state='completed',error_message=NULL,updated_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP() "
            . 'WHERE scope=:scope AND migration=:migration AND direction=:direction'
        );
        $statement->execute(compact('scope', 'migration', 'direction'));
    }
}
