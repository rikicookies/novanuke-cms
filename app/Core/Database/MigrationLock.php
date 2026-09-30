<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use PDO;
use RuntimeException;

final class MigrationLock
{
    private ?string $name = null;

    public function __construct(private readonly PDO $database, private readonly int $timeoutSeconds = 10)
    {
    }

    public function acquire(): void
    {
        if ($this->name !== null) throw new RuntimeException('Migration lock is already held by this runner.');
        $schema = (string) $this->database->query('SELECT DATABASE()')->fetchColumn();
        if ($schema === '') throw new RuntimeException('A selected database is required for migration locking.');
        $this->name = 'novanuke:migrate:' . substr(hash('sha256', $schema), 0, 40);
        $statement = $this->database->prepare('SELECT GET_LOCK(:name, :timeout)');
        $statement->bindValue(':name', $this->name);
        $statement->bindValue(':timeout', max(0, min(60, $this->timeoutSeconds)), PDO::PARAM_INT);
        $statement->execute();
        if ((int) $statement->fetchColumn() !== 1) {
            $this->name = null;
            throw new RuntimeException('Another migration or module schema operation is already running.');
        }
    }

    public function release(): void
    {
        if ($this->name === null) return;
        $name = $this->name;
        $this->name = null;
        try {
            $statement = $this->database->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute(['name' => $name]);
        } catch (\Throwable) {
            // Closing the database connection releases a MySQL advisory lock.
        }
    }
}
