<?php

declare(strict_types=1);

namespace NovaNuke\Core\Maintenance;

use InvalidArgumentException;

final class MaintenancePruning
{
    /** @var array<string,int> */
    private array $results = [];

    public function __construct(public readonly bool $dryRun)
    {
    }

    public function add(string $name, int $records): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,79}$/', $name) || $records < 0) {
            throw new InvalidArgumentException('Invalid maintenance result.');
        }
        $this->results[$name] = ($this->results[$name] ?? 0) + $records;
    }

    /** @return array<string,int> */
    public function results(): array
    {
        ksort($this->results);
        return $this->results;
    }
}
