<?php

declare(strict_types=1);

namespace NovaNuke\Core\Settings;

use PDO;

final class SettingsRepository
{
    /** @var array<string,string>|null */ private ?array $values = null;
    public function __construct(private readonly PDO $database)
    {
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $values = $this->values();
        if (! array_key_exists($key, $values)) {
            return $default;
        }
        return filter_var($values[$key], FILTER_VALIDATE_BOOL);
    }

    public function setBoolean(string $key, bool $value, string $group = 'general'): void
    {
        $this->write($key, $value ? '1' : '0', 'boolean', $group);
    }

    public function string(string $key, string $default = ''): string
    {
        $values = $this->values();
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public function setString(string $key, string $value, string $group = 'general'): void
    {
        $this->write($key, $value, 'string', $group);
    }

    public function integer(string $key, int $default = 0, int $minimum = PHP_INT_MIN, int $maximum = PHP_INT_MAX): int
    {
        $value = filter_var($this->string($key, ''), FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            return $default;
        }

        return $value;
    }

    public function setInteger(string $key, int $value, string $group = 'general'): void
    {
        $this->write($key, (string) $value, 'integer', $group);
    }

    /** @param array<string, array{value:string,type:string,group:string}> $settings */
    public function setMany(array $settings): void
    {
        $startedTransaction = ! $this->database->inTransaction();
        if ($startedTransaction) {
            $this->database->beginTransaction();
        }

        try {
            foreach ($settings as $key => $setting) {
                if (! in_array($setting['type'], ['string', 'integer', 'boolean'], true)) {
                    throw new \InvalidArgumentException('Unsupported setting type.');
                }
                $this->write($key, $setting['value'], $setting['type'], $setting['group']);
            }
            if ($startedTransaction) {
                $this->database->commit();
            }
        } catch (\Throwable $error) {
            if ($startedTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            $this->values = null;
            throw $error;
        }
    }

    private function write(string $key, string $value, string $type, string $group): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO settings (`key`, `value`, `type`, group_name, created_at, updated_at) '
            . 'VALUES (:key, :value, :type, :group_name, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), '
            . 'group_name = VALUES(group_name), updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute(['key' => $key, 'value' => $value, 'type' => $type, 'group_name' => $group]);
        $this->values = null;
    }

    /** @return array<string,string> */
    private function values(): array
    {
        if ($this->values !== null) return $this->values;
        $rows=$this->database->query('SELECT `key`,`value` FROM settings')->fetchAll();$this->values=[];
        foreach($rows as$row)$this->values[(string)$row['key']]=(string)$row['value'];
        return $this->values;
    }
}
