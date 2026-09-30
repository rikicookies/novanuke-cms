<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

use PDO;

final class MembershipHealthCheck
{
    public function __construct(private readonly PDO $database) {}

    /** @return list<array{name:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];

        $columns=$this->columns();
        foreach(['plan_key','source','note','activated_event_at','expired_event_at'] as $column){
            $this->add(
                $checks,
                'Schema ' . $column,
                in_array($column,$columns,true),
                in_array($column,$columns,true)
                    ? "user_entitlements.{$column} is available."
                    : "Missing user_entitlements.{$column}. Run pending migrations.",
            );
        }

        $usersColumns=$this->userColumns();
        $booleanLeak=array_values(array_intersect(['is_vip','vip_active'],$usersColumns));
        $this->add(
            $checks,
            'No persisted VIP boolean',
            $booleanLeak===[],
            $booleanLeak===[]?'users has no VIP boolean state.':'Unexpected users column(s): '.implode(', ',$booleanLeak),
        );

        if(!in_array('plan_key',$columns,true)){
            return $checks;
        }

        $validPlans=array_keys((new MembershipPlanCatalog())->all());
        $placeholders=implode(',',array_fill(0,count($validPlans),'?'));
        $statement=$this->database->prepare(
            "SELECT COUNT(*) FROM user_entitlements WHERE entitlement='vip' "
            . "AND plan_key IS NOT NULL AND plan_key<>'vip-custom' AND plan_key NOT IN ({$placeholders})"
        );
        $statement->execute($validPlans);
        $invalidPlans=(int)$statement->fetchColumn();
        $this->add($checks,'Known plan keys',$invalidPlans===0,$invalidPlans===0?'All VIP plan keys are recognized.':"{$invalidPlans} VIP grant(s) use an unknown plan key.");

        $activeDuplicates=$this->count(
            "SELECT COUNT(*) FROM (SELECT user_id FROM user_entitlements WHERE entitlement='vip' "
            . "AND revoked_at IS NULL AND starts_at<=UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) "
            . "GROUP BY user_id HAVING COUNT(*)>1) x"
        );
        $this->add($checks,'Single active VIP per user',$activeDuplicates===0,$activeDuplicates===0?'No users have multiple active VIP grants.':"{$activeDuplicates} user(s) have multiple active VIP grants.");

        $scheduledDuplicates=$this->count(
            "SELECT COUNT(*) FROM (SELECT user_id FROM user_entitlements WHERE entitlement='vip' "
            . "AND revoked_at IS NULL AND starts_at>UTC_TIMESTAMP() GROUP BY user_id HAVING COUNT(*)>1) x"
        );
        $this->add($checks,'Single scheduled VIP per user',$scheduledDuplicates===0,$scheduledDuplicates===0?'No users have multiple future VIP grants.':"{$scheduledDuplicates} user(s) have multiple future VIP grants.");

        $overlaps=$this->count(
            "SELECT COUNT(DISTINCT a.user_id) FROM user_entitlements a JOIN user_entitlements b "
            . "ON b.user_id=a.user_id AND b.entitlement='vip' AND b.revoked_at IS NULL AND b.starts_at>UTC_TIMESTAMP() "
            . "WHERE a.entitlement='vip' AND a.revoked_at IS NULL AND a.starts_at<=UTC_TIMESTAMP() "
            . "AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) "
            . "AND (a.expires_at IS NULL OR b.starts_at<a.expires_at)"
        );
        $this->add($checks,'No active/future overlap',$overlaps===0,$overlaps===0?'Active and scheduled VIP periods do not overlap.':"{$overlaps} user(s) have overlapping active/future VIP periods.");

        $orphanUsers=$this->count(
            "SELECT COUNT(*) FROM user_entitlements ue LEFT JOIN users u ON u.id=ue.user_id WHERE u.id IS NULL"
        );
        $this->add($checks,'Entitlement ownership',$orphanUsers===0,$orphanUsers===0?'Every membership grant belongs to an existing user.':"{$orphanUsers} orphan membership grant(s) exist.");

        return $checks;
    }

    private function count(string $sql): int
    {
        return (int)$this->database->query($sql)->fetchColumn();
    }

    /** @return list<string> */
    private function columns(): array
    {
        $statement=$this->database->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema=DATABASE() AND table_name='user_entitlements'"
        );
        return array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function userColumns(): array
    {
        $statement=$this->database->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema=DATABASE() AND table_name='users'"
        );
        return array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<array{name:string,passed:bool,detail:string}> $checks */
    private function add(array &$checks,string $name,bool $passed,string $detail): void
    {
        $checks[]=['name'=>$name,'passed'=>$passed,'detail'=>$detail];
    }
}
