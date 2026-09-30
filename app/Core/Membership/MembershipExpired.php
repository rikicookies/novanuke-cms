<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

final readonly class MembershipExpired
{
    public function __construct(
        public int $entitlementId,
        public int $userId,
        public string $planKey,
        public string $expiredAt,
    ) {}
}
