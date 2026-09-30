<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

interface MembershipProvisionerInterface
{
    /** @return array<string,mixed> */
    public function provision(
        int $userId,
        string $planKey,
        string $source,
        ?string $reference = null,
    ): array;
}
