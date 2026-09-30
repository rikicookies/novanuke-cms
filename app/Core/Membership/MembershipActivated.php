<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

final readonly class MembershipActivated
{
    public function __construct(
        public int $entitlementId,
        public int $userId,
        public string $planKey,
        public ?string $expiresAt,
        public bool $lifetime,
    ) {}
}
