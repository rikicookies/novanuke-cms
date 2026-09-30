<?php

declare(strict_types=1);

namespace NovaNuke\Core\Media;

use InvalidArgumentException;

final class MediaUsageChecking
{
    /** @var array<string,int> */
    private array $uses = [];
    public function __construct(public readonly string $publicPath) {}
    public function add(string $source, int $count): void
    {
        if (! preg_match('/^[a-z][a-z0-9.-]{1,79}$/', $source) || $count < 0) throw new InvalidArgumentException('Invalid media usage result.');
        $this->uses[$source] = ($this->uses[$source] ?? 0) + $count;
    }
    public function total(): int { return array_sum($this->uses); }
    /** @return array<string,int> */
    public function uses(): array { ksort($this->uses); return $this->uses; }
}
