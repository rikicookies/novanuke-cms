<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

final readonly class MembershipAssigned
{
    public function __construct(
        public int $userId,
        public string $planKey,
        public ?string $expiresAt,
        public bool $lifetime,
        public ?int $actorId,
        public string $source = 'manual',
    ) {}
}
