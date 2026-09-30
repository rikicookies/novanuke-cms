<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

use InvalidArgumentException;

final class MembershipPlanCatalog
{
    /** @return array<string,array{key:string,name:string,entitlement:?string,days:?int,lifetime:bool}> */
    public function all(): array
    {
        return [
            'free' => ['key' => 'free', 'name' => 'Free', 'entitlement' => null, 'days' => null, 'lifetime' => false],
            'vip-30' => ['key' => 'vip-30', 'name' => 'VIP 30 days', 'entitlement' => 'vip', 'days' => 30, 'lifetime' => false],
            'vip-90' => ['key' => 'vip-90', 'name' => 'VIP 90 days', 'entitlement' => 'vip', 'days' => 90, 'lifetime' => false],
            'vip-annual' => ['key' => 'vip-annual', 'name' => 'VIP Annual', 'entitlement' => 'vip', 'days' => 365, 'lifetime' => false],
            'vip-lifetime' => ['key' => 'vip-lifetime', 'name' => 'VIP Lifetime', 'entitlement' => 'vip', 'days' => null, 'lifetime' => true],
        ];
    }

    /** @return array{key:string,name:string,entitlement:?string,days:?int,lifetime:bool} */
    public function get(string $key): array
    {
        $plans = $this->all();
        if (! isset($plans[$key])) {
            throw new InvalidArgumentException('Unknown membership plan.');
        }
        return $plans[$key];
    }

    public function exists(string $key): bool
    {
        return isset($this->all()[$key]);
    }
}
