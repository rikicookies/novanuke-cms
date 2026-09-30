<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use PDO;
use RuntimeException;

final class MigrationSchema
{
    public static function tableExists(PDO $database, string $table): bool
    {
        self::identifier($table);
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name=:table AND table_type=\'BASE TABLE\''
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    }

    public static function columnExists(PDO $database, string $table, string $column): bool
    {
        self::identifier($table);
        self::identifier($column);
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() === 1;
    }

    public static function indexExists(PDO $database, string $table, string $index): bool
    {
        self::identifier($table);
        self::identifier($index);
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . 'WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:index'
        );
        $statement->execute(['table' => $table, 'index' => $index]);
        return (int) $statement->fetchColumn() > 0;
    }

    /** @param list<string> $columns */
    public static function columnsExist(PDO $database, string $table, array $columns): bool
    {
        foreach ($columns as $column) if (! self::columnExists($database, $table, $column)) return false;
        return true;
    }

    /** @param list<string> $columns */
    public static function columnsAbsent(PDO $database, string $table, array $columns): bool
    {
        foreach ($columns as $column) if (self::columnExists($database, $table, $column)) return false;
        return true;
    }

    public static function valueExists(PDO $database, string $table, string $column, string $value): bool
    {
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM ' . self::quoted($table) . ' WHERE ' . self::quoted($column) . '=:value'
        );
        $statement->execute(['value' => $value]);
        return (int) $statement->fetchColumn() > 0;
    }

    public static function columnIsNullable(PDO $database, string $table, string $column): bool
    {
        $statement = $database->prepare(
            'SELECT is_nullable FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column LIMIT 1'
        );
        $statement->execute(compact('table', 'column'));
        return $statement->fetchColumn() === 'YES';
    }

    /** @param list<string> $tables */
    public static function tablesExist(PDO $database, array $tables): bool
    {
        foreach ($tables as $table) if (! self::tableExists($database, $table)) return false;
        return true;
    }

    /** @param list<string> $tables */
    public static function tablesAbsent(PDO $database, array $tables): bool
    {
        foreach ($tables as $table) if (self::tableExists($database, $table)) return false;
        return true;
    }

    public static function addColumn(PDO $database, string $table, string $column, string $definition): void
    {
        if (self::columnExists($database, $table, $column)) return;
        $database->exec('ALTER TABLE ' . self::quoted($table) . ' ADD COLUMN ' . self::quoted($column) . ' ' . $definition);
    }

    public static function dropColumn(PDO $database, string $table, string $column): void
    {
        if (! self::columnExists($database, $table, $column)) return;
        $database->exec('ALTER TABLE ' . self::quoted($table) . ' DROP COLUMN ' . self::quoted($column));
    }

    public static function createIndex(PDO $database, string $table, string $index, string $columns): void
    {
        if (self::indexExists($database, $table, $index)) return;
        $database->exec('CREATE INDEX ' . self::quoted($index) . ' ON ' . self::quoted($table) . ' (' . $columns . ')');
    }

    public static function dropIndex(PDO $database, string $table, string $index): void
    {
        if (! self::indexExists($database, $table, $index)) return;
        $database->exec('DROP INDEX ' . self::quoted($index) . ' ON ' . self::quoted($table));
    }

    private static function quoted(string $identifier): string
    {
        self::identifier($identifier);
        return '`' . $identifier . '`';
    }

    private static function identifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $identifier) !== 1) {
            throw new RuntimeException('Unsafe migration schema identifier.');
        }
    }
}
