<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use PDO;

/** Declarative postconditions for bundled schema/data migrations. */
trait VerifiesMigrationState
{
    public function isApplied(PDO $database): bool
    {
        foreach ($this->spec('MIGRATION_TABLES') as $table) {
            if (! MigrationSchema::tableExists($database, $table)) return false;
        }
        foreach ($this->spec('MIGRATION_COLUMNS') as $table => $columns) {
            if (! MigrationSchema::columnsExist($database, $table, $columns)) return false;
        }
        foreach ($this->spec('MIGRATION_INDEXES') as $table => $indexes) {
            foreach ($indexes as $index) if (! MigrationSchema::indexExists($database, $table, $index)) return false;
        }
        foreach ($this->spec('MIGRATION_VALUES') as [$table, $column, $value]) {
            if (! MigrationSchema::valueExists($database, $table, $column, $value)) return false;
        }
        return true;
    }

    public function isRolledBack(PDO $database): bool
    {
        foreach ($this->spec('MIGRATION_TABLES') as $table) {
            if (MigrationSchema::tableExists($database, $table)) return false;
        }
        foreach ($this->spec('MIGRATION_COLUMNS') as $table => $columns) {
            if (! MigrationSchema::columnsAbsent($database, $table, $columns)) return false;
        }
        foreach ($this->spec('MIGRATION_INDEXES') as $table => $indexes) {
            foreach ($indexes as $index) if (MigrationSchema::indexExists($database, $table, $index)) return false;
        }
        foreach ($this->spec('MIGRATION_VALUES') as [$table, $column, $value]) {
            if (MigrationSchema::tableExists($database, $table)
                && MigrationSchema::valueExists($database, $table, $column, $value)) return false;
        }
        return true;
    }

    /** @return array<mixed> */
    private function spec(string $name): array
    {
        $constant = static::class . '::' . $name;
        $value = defined($constant) ? constant($constant) : [];
        return is_array($value) ? $value : [];
    }
}
