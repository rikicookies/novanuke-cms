<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

final readonly class MembershipScheduleCancelled
{
    public function __construct(
        public int $userId,
        public string $planKey,
        public string $startsAt,
        public ?int $actorId,
    ) {}
}
