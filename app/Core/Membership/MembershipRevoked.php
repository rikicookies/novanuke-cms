<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

final readonly class MembershipRevoked
{
    public function __construct(
        public int $userId,
        public ?string $planKey,
        public ?int $actorId,
        public string $source = 'manual',
    ) {}
}
