<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

interface MembershipManagerInterface
{
    /** @return array<string,mixed> */
    public function status(int $userId): array;

    public function isVip(int $userId): bool;

    /** @return array<string,mixed>|null */
    public function nextScheduled(int $userId): ?array;

    /** @return array<string,array{key:string,name:string,entitlement:?string,days:?int,lifetime:bool}> */
    public function plans(): array;

    /** @return array<string,mixed> */
    public function assign(int $userId, string $planKey, int $actorId, ?string $note = null): array;

    /** @return array<string,mixed> */
    public function grantDays(int $userId, int $days, int $actorId, ?string $note = null): array;

    /** @return array<string,mixed> */
    public function extendDays(int $userId, int $days, int $actorId, ?string $note = null): array;

    /** @return array<string,mixed> */
    public function schedule(int $userId, string $planKey, string $startsAt, int $actorId, ?string $note = null): array;

    public function cancelScheduled(int $userId, ?int $actorId = null): bool;

    public function revoke(int $userId, ?int $actorId = null, string $source = 'manual'): void;
}
