<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

use NovaNuke\Core\Access\EntitlementService;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Events\EventName;

final class MembershipService implements MembershipManagerInterface, MembershipProvisionerInterface
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly MembershipPlanCatalog $plans = new MembershipPlanCatalog(),
        private readonly ?EventDispatcher $events = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(int $userId): array
    {
        $status=$this->entitlements->status($userId, EntitlementService::VIP);
        if ($status === null || ! ($status['active'] ?? false)) {
            return [
                'plan_key'=>'free',
                'name'=>'Free',
                'active'=>true,
                'vip'=>false,
                'lifetime'=>false,
                'starts_at'=>null,
                'expires_at'=>null,
                'revoked_at'=>$status['revoked_at']??null,
            ];
        }

        $planKey=(string)($status['plan_key']??'vip-custom');
        $plan=$this->plans->exists($planKey)?$this->plans->get($planKey):null;
        $status['name']=$plan['name']??'VIP';
        $status['vip']=true;
        return $status;
    }

    public function isVip(int $userId): bool
    {
        return $this->entitlements->has($userId, EntitlementService::VIP);
    }

    public function nextScheduled(int $userId): ?array
    {
        $status=$this->entitlements->nextScheduled($userId, EntitlementService::VIP);
        if($status===null) return null;
        $key=(string)($status['plan_key']??'vip-custom');
        $plan=$this->plans->exists($key)?$this->plans->get($key):null;
        $status['name']=$plan['name']??'VIP';
        return $status;
    }

    /** @return array<string,array{key:string,name:string,entitlement:?string,days:?int,lifetime:bool}> */
    public function plans(): array
    {
        return $this->plans->all();
    }

    /** @return array<string,mixed> */
    public function assign(int $userId, string $planKey, int $actorId, ?string $note = null): array
    {
        $plan = $this->plans->get($planKey);
        $before = $this->status($userId);
        $scheduledBefore = $this->nextScheduled($userId);

        if ($plan['entitlement'] === null) {
            $this->entitlements->revoke($userId, EntitlementService::VIP);
            $cancelledScheduled=$this->entitlements->cancelScheduled($userId, EntitlementService::VIP);
            if ($cancelledScheduled && $scheduledBefore !== null && $this->events !== null) {
                $this->events->dispatch(EventName::MEMBERSHIP_SCHEDULE_CANCELLED,new MembershipScheduleCancelled(
                    $userId,(string)($scheduledBefore['plan_key']??'vip-custom'),(string)($scheduledBefore['starts_at']??''),$actorId
                ));
            }
            if (($before['vip'] ?? false) && $this->events !== null) {
                $this->events->dispatch(EventName::MEMBERSHIP_REVOKED, new MembershipRevoked(
                    $userId,
                    (string) ($before['plan_key'] ?? 'vip-custom'),
                    $actorId,
                    'manual',
                ));
            }
            return $this->status($userId);
        }

        $this->entitlements->replace(
            $userId,
            EntitlementService::VIP,
            $plan['days'],
            $actorId,
            $planKey,
            'manual',
            $note,
        );

        $status = $this->status($userId);
        if ($scheduledBefore !== null && $this->events !== null) {
            $this->events->dispatch(EventName::MEMBERSHIP_SCHEDULE_CANCELLED,new MembershipScheduleCancelled(
                $userId,(string)($scheduledBefore['plan_key']??'vip-custom'),(string)($scheduledBefore['starts_at']??''),$actorId
            ));
        }
        if ($this->events !== null) {
            $this->events->dispatch(EventName::MEMBERSHIP_ASSIGNED, new MembershipAssigned(
                $userId,
                $planKey,
                $status['expires_at'] ?? null,
                (bool) ($status['lifetime'] ?? false),
                $actorId,
                'manual',
            ));
        }
        return $status;
    }

    /** @return array<string,mixed> */
    public function provision(
        int $userId,
        string $planKey,
        string $source,
        ?string $reference = null,
    ): array {
        if($source==='manual') throw new \InvalidArgumentException('External provisioning source cannot be manual.');
        $plan=$this->plans->get($planKey);
        if($plan['entitlement']===null) throw new \InvalidArgumentException('Free cannot be externally provisioned.');
        if($reference!==null&&mb_strlen($reference)>191) throw new \InvalidArgumentException('Provisioning reference is too long.');

        $scheduledBefore=$this->nextScheduled($userId);
        $this->entitlements->replace(
            $userId,
            EntitlementService::VIP,
            $plan['days'],
            null,
            $planKey,
            $source,
            $reference,
        );

        $status=$this->status($userId);
        if($scheduledBefore!==null&&$this->events!==null){
            $this->events->dispatch(EventName::MEMBERSHIP_SCHEDULE_CANCELLED,new MembershipScheduleCancelled(
                $userId,(string)($scheduledBefore['plan_key']??'vip-custom'),(string)($scheduledBefore['starts_at']??''),null
            ));
        }
        if($this->events!==null){
            $this->events->dispatch(EventName::MEMBERSHIP_ASSIGNED,new MembershipAssigned(
                $userId,$planKey,$status['expires_at']??null,(bool)($status['lifetime']??false),null,$source
            ));
        }
        return $status;
    }

    /** @return array<string,mixed> */
    public function grantDays(int $userId, int $days, int $actorId, ?string $note = null): array
    {
        $scheduledBefore=$this->nextScheduled($userId);
        $this->entitlements->grant($userId, EntitlementService::VIP, $days, $actorId);

        if($scheduledBefore!==null){
            $cancelled=$this->entitlements->cancelScheduled($userId,EntitlementService::VIP);
            if($cancelled&&$this->events!==null){
                $this->events->dispatch(EventName::MEMBERSHIP_SCHEDULE_CANCELLED,new MembershipScheduleCancelled(
                    $userId,
                    (string)($scheduledBefore['plan_key']??'vip-custom'),
                    (string)($scheduledBefore['starts_at']??''),
                    $actorId,
                ));
            }
        }

        $status=$this->status($userId);
        if($this->events!==null){
            $this->events->dispatch(EventName::MEMBERSHIP_ASSIGNED,new MembershipAssigned(
                $userId,
                (string)($status['plan_key']??'vip-custom'),
                $status['expires_at']??null,
                false,
                $actorId,
                'manual',
            ));
        }
        return $status;
    }

    /** @return array<string,mixed> */
    public function extendDays(int $userId, int $days, int $actorId, ?string $note = null): array
    {
        $this->entitlements->extend($userId,EntitlementService::VIP,$days,$actorId,$note);
        $status=$this->status($userId);
        if($this->events!==null&&is_string($status['expires_at']??null)){
            $this->events->dispatch(EventName::MEMBERSHIP_EXTENDED,new MembershipExtended(
                $userId,
                (string)($status['plan_key']??'vip-custom'),
                $days,
                (string)$status['expires_at'],
                $actorId,
            ));
        }
        return $status;
    }

    /** @return array<string,mixed> */
    public function schedule(int $userId, string $planKey, string $startsAt, int $actorId, ?string $note = null): array
    {
        $plan=$this->plans->get($planKey);
        if($plan['entitlement']===null) throw new \InvalidArgumentException('Free cannot be scheduled as a future VIP grant.');
        $this->entitlements->schedule(
            $userId,EntitlementService::VIP,$plan['days'],$startsAt,$actorId,$planKey,'manual',$note
        );
        $scheduled=$this->nextScheduled($userId) ?? [];
        if($scheduled!==[]&&$this->events!==null){
            $this->events->dispatch(EventName::MEMBERSHIP_SCHEDULED,new MembershipScheduled(
                $userId,
                $planKey,
                (string)($scheduled['starts_at']??$startsAt),
                $scheduled['expires_at']??null,
                (bool)($scheduled['lifetime']??false),
                $actorId,
            ));
        }
        return $scheduled;
    }

    public function cancelScheduled(int $userId, ?int $actorId = null): bool
    {
        $scheduled=$this->nextScheduled($userId);
        $cancelled=$this->entitlements->cancelScheduled($userId,EntitlementService::VIP);
        if($cancelled&&$scheduled!==null&&$this->events!==null){
            $this->events->dispatch(EventName::MEMBERSHIP_SCHEDULE_CANCELLED,new MembershipScheduleCancelled(
                $userId,
                (string)($scheduled['plan_key']??'vip-custom'),
                (string)($scheduled['starts_at']??''),
                $actorId,
            ));
        }
        return $cancelled;
    }

    public function revoke(int $userId, ?int $actorId = null, string $source = 'manual'): void
    {
        $before=$this->status($userId);
        $this->entitlements->revoke($userId, EntitlementService::VIP);
        if(($before['vip']??false)&&$this->events!==null){
            $this->events->dispatch(EventName::MEMBERSHIP_REVOKED,new MembershipRevoked(
                $userId,
                (string)($before['plan_key']??'vip-custom'),
                $actorId,
                $source,
            ));
        }
    }
}
