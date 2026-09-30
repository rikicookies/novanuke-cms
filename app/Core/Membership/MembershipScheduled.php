<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

final readonly class MembershipScheduled
{
    public function __construct(
        public int $userId,
        public string $planKey,
        public string $startsAt,
        public ?string $expiresAt,
        public bool $lifetime,
        public ?int $actorId,
    ) {}
}
