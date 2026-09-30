<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Events\EventName;
use PDO;
use Throwable;

final class MembershipActivationProcessor
{
    public function __construct(
        private readonly PDO $database,
        private readonly EventDispatcher $events,
    ) {}

    public function process(bool $dryRun = false): int
    {
        $statement=$this->database->query(
            "SELECT id,user_id,COALESCE(plan_key,'vip-custom') plan_key,expires_at "
            . "FROM user_entitlements WHERE entitlement='vip' AND revoked_at IS NULL "
            . "AND starts_at<=UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) "
            . "AND activated_event_at IS NULL ORDER BY id LIMIT 500"
        );
        $rows=$statement->fetchAll();
        if($dryRun) return count($rows);

        $processed=0;
        foreach($rows as $row){
            $this->database->beginTransaction();
            try{
                $locked=$this->database->prepare(
                    "SELECT id,user_id,COALESCE(plan_key,'vip-custom') plan_key,expires_at "
                    . "FROM user_entitlements WHERE id=:id AND entitlement='vip' AND revoked_at IS NULL "
                    . "AND starts_at<=UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) "
                    . "AND activated_event_at IS NULL FOR UPDATE"
                );
                $locked->execute(['id'=>(int)$row['id']]);
                $current=$locked->fetch();
                if(!is_array($current)){
                    $this->database->commit();
                    continue;
                }

                $this->events->dispatch(EventName::MEMBERSHIP_ACTIVATED,new MembershipActivated(
                    (int)$current['id'],
                    (int)$current['user_id'],
                    (string)$current['plan_key'],
                    $current['expires_at']===null?null:(string)$current['expires_at'],
                    $current['expires_at']===null,
                ));
                $mark=$this->database->prepare(
                    'UPDATE user_entitlements SET activated_event_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() '
                    . 'WHERE id=:id AND activated_event_at IS NULL'
                );
                $mark->execute(['id'=>(int)$current['id']]);
                if($mark->rowCount()!==1) throw new \RuntimeException('Membership activation marker could not be recorded.');
                $this->database->commit();
                $processed++;
            }catch(Throwable $error){
                if($this->database->inTransaction()) $this->database->rollBack();
                throw $error;
            }
        }
        return $processed;
    }
}
