<?php

declare(strict_types=1);

namespace NovaNuke\Core\Container;

use Closure;
use InvalidArgumentException;

final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    private ?string $owner = null;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $ownerChanges = [];

    public function beginOwner(string $owner): void
    {
        $this->owner = $owner;
    }

    public function endOwner(): void
    {
        $this->owner = null;
    }

    public function commitOwner(string $owner): void
    {
        unset($this->ownerChanges[$owner]);
    }

    public function removeOwner(string $owner): void
    {
        foreach (array_reverse($this->ownerChanges[$owner] ?? [], true) as $id => $old) {
            $bindingMatches = array_key_exists($id, $this->bindings) === $old['binding_after_exists']
                && (! $old['binding_after_exists'] || $this->bindings[$id] === $old['binding_after']);
            if ($bindingMatches) {
                if ($old['binding_exists']) $this->bindings[$id] = $old['binding']; else unset($this->bindings[$id]);
            }
            $instanceMatches = array_key_exists($id, $this->instances) === $old['instance_after_exists']
                && (! $old['instance_after_exists'] || $this->instances[$id] === $old['instance_after']);
            if ($instanceMatches) {
                if ($old['instance_exists']) $this->instances[$id] = $old['instance']; else unset($this->instances[$id]);
            }
        }
        unset($this->ownerChanges[$owner]);
    }

    public function bind(string $id, Closure $factory): void
    {
        $this->rememberBefore($id);
        $this->bindings[$id] = $factory;
        $this->rememberAfter($id);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->rememberBefore($id);
        $this->instances[$id] = $instance;
        $this->rememberAfter($id);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (! array_key_exists($id, $this->bindings)) {
            throw new InvalidArgumentException("Service is not registered: {$id}");
        }

        return $this->instances[$id] = ($this->bindings[$id])($this);
    }
    private function rememberBefore(string $id): void
    {
        if ($this->owner === null || array_key_exists($id, $this->ownerChanges[$this->owner] ?? [])) return;
        $this->ownerChanges[$this->owner][$id] = [
            'binding_exists' => array_key_exists($id, $this->bindings), 'binding' => $this->bindings[$id] ?? null,
            'instance_exists' => array_key_exists($id, $this->instances), 'instance' => $this->instances[$id] ?? null,
            'binding_after_exists' => false, 'binding_after' => null,
            'instance_after_exists' => false, 'instance_after' => null,
        ];
    }

    private function rememberAfter(string $id): void
    {
        if ($this->owner === null || ! isset($this->ownerChanges[$this->owner][$id])) return;
        $change =& $this->ownerChanges[$this->owner][$id];
        $change['binding_after_exists'] = array_key_exists($id, $this->bindings);
        $change['binding_after'] = $this->bindings[$id] ?? null;
        $change['instance_after_exists'] = array_key_exists($id, $this->instances);
        $change['instance_after'] = $this->instances[$id] ?? null;
    }
}
