<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

final readonly class MembershipExtended
{
    public function __construct(
        public int $userId,
        public string $planKey,
        public int $days,
        public string $expiresAt,
        public ?int $actorId,
    ) {}
}
