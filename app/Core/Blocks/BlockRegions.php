<?php

declare(strict_types=1);

namespace NovaNuke\Core\Blocks;

use ArrayAccess;
use IteratorAggregate;
use LogicException;
use Traversable;

/** @implements ArrayAccess<string,list<array<string,mixed>>> @implements IteratorAggregate<string,list<array<string,mixed>>> */
final class BlockRegions implements ArrayAccess, IteratorAggregate
{
    /** @var array<string,list<array<string,mixed>>> */
    private array $regions;

    /** @param list<string> $positions */
    public function __construct(array $positions)
    {
        $this->regions = array_fill_keys($positions, []);
    }

    /** @param array<string,mixed> $block */
    public function add(string $position, array $block): void
    {
        if (! array_key_exists($position, $this->regions)) {
            throw new LogicException('Unknown block position.');
        }
        $this->regions[$position][] = $block;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->regions);
    }

    /** @return list<array<string,mixed>> */
    public function offsetGet(mixed $offset): array
    {
        return is_string($offset) ? ($this->regions[$offset] ?? []) : [];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Block regions are mutated through add().');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Block regions cannot be removed.');
    }

    public function getIterator(): Traversable
    {
        yield from $this->regions;
    }
}
