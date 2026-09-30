<?php

declare(strict_types=1);

namespace NovaNuke\Core\Events;

use Closure;
use NovaNuke\Core\Modules\ModuleMutationScope;

final class EventDispatcher
{
    /** @var array<string, list<array{priority: int, listener: Closure}>> */
    private array $listeners = [];

    public function listen(string $event, Closure $listener, int $priority = 0): void
    {
        $registration = ['priority' => $priority, 'listener' => $listener];
        $this->listeners[$event][] = $registration;
        ModuleMutationScope::onRollback(function () use ($event, $registration): void {
            foreach ($this->listeners[$event] ?? [] as $index => $candidate) {
                if ($candidate === $registration) {unset($this->listeners[$event][$index]);$this->listeners[$event]=array_values($this->listeners[$event]);break;}
            }
            if (($this->listeners[$event] ?? []) === []) unset($this->listeners[$event]);
        });
        usort(
            $this->listeners[$event],
            static fn (array $left, array $right): int => $right['priority'] <=> $left['priority'],
        );
    }

    public function dispatch(string $event, object $payload): object
    {
        foreach ($this->listeners[$event] ?? [] as $registration) {
            ($registration['listener'])($payload);
        }

        return $payload;
    }

    public function listenerCount(string $event): int
    {
        return count($this->listeners[$event] ?? []);
    }
}
