<?php

declare(strict_types=1);

namespace NovaNuke\Core\Access;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

final class EntitlementService
{
    public const VIP = 'vip';

    public function __construct(private readonly PDO $database)
    {
    }

    public function has(int $userId, string $entitlement): bool
    {
        $this->assertKey($entitlement);
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM user_entitlements '
            . 'WHERE user_id=:user_id AND entitlement=:entitlement '
            . 'AND starts_at<=UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) AND revoked_at IS NULL'
        );
        $statement->execute(['user_id' => $userId, 'entitlement' => $entitlement]);
        return (int) $statement->fetchColumn() > 0;
    }

    /** @return array<string,mixed>|null */
    public function status(int $userId, string $entitlement): ?array
    {
        return $this->current($userId, $entitlement)
            ?? $this->nextScheduled($userId, $entitlement)
            ?? $this->latest($userId, $entitlement);
    }

    /** @return array<string,mixed>|null */
    public function current(int $userId, string $entitlement): ?array
    {
        $this->assertKey($entitlement);
        $statement=$this->database->prepare(
            'SELECT id,entitlement,plan_key,source,note,starts_at,expires_at,revoked_at,created_at,updated_at '
            . 'FROM user_entitlements WHERE user_id=:user_id AND entitlement=:entitlement '
            . 'AND revoked_at IS NULL AND starts_at<=UTC_TIMESTAMP() '
            . 'AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) '
            . 'ORDER BY expires_at IS NULL DESC,expires_at DESC,id DESC LIMIT 1'
        );
        $statement->execute(['user_id'=>$userId,'entitlement'=>$entitlement]);
        $record=$statement->fetch();
        if(!is_array($record)) return null;
        $record['active']=true;
        $record['scheduled']=false;
        $record['lifetime']=$record['expires_at']===null;
        return $record;
    }

    /** @return array<string,mixed>|null */
    public function nextScheduled(int $userId, string $entitlement): ?array
    {
        $this->assertKey($entitlement);
        $statement=$this->database->prepare(
            'SELECT id,entitlement,plan_key,source,note,starts_at,expires_at,revoked_at,created_at,updated_at '
            . 'FROM user_entitlements WHERE user_id=:user_id AND entitlement=:entitlement '
            . 'AND revoked_at IS NULL AND starts_at>UTC_TIMESTAMP() '
            . 'ORDER BY starts_at ASC,id ASC LIMIT 1'
        );
        $statement->execute(['user_id'=>$userId,'entitlement'=>$entitlement]);
        $record=$statement->fetch();
        if(!is_array($record)) return null;
        $record['active']=false;
        $record['scheduled']=true;
        $record['lifetime']=$record['expires_at']===null;
        return $record;
    }

    /** @return array<string,mixed>|null */
    private function latest(int $userId, string $entitlement): ?array
    {
        $statement=$this->database->prepare(
            'SELECT id,entitlement,plan_key,source,note,starts_at,expires_at,revoked_at,created_at,updated_at '
            . 'FROM user_entitlements WHERE user_id=:user_id AND entitlement=:entitlement ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['user_id'=>$userId,'entitlement'=>$entitlement]);
        $record=$statement->fetch();
        if(!is_array($record)) return null;
        $record['active']=self::recordIsActive($record);
        $record['scheduled']=!$record['active']&&$record['revoked_at']===null
            && is_string($record['starts_at']??null)
            && new DateTimeImmutable($record['starts_at'],new DateTimeZone('UTC'))>new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $record['lifetime']=$record['active']&&$record['expires_at']===null;
        return $record;
    }

    /** @param array<string,mixed> $record */
    public static function recordIsActive(array $record, ?DateTimeImmutable $at = null): bool
    {
        if (($record['revoked_at'] ?? null) !== null || ! is_string($record['starts_at'] ?? null)) return false;
        $expires = $record['expires_at'] ?? null;
        if ($expires !== null && ! is_string($expires)) return false;
        $utc = new DateTimeZone('UTC');
        $at = ($at ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $starts = new DateTimeImmutable($record['starts_at'], $utc);
        if ($starts > $at) return false;
        return $expires === null || new DateTimeImmutable($expires, $utc) > $at;
    }

    /** Legacy-compatible additive grant. */
    public function grant(int $userId, string $entitlement, int $days, int $grantedBy): string
    {
        $this->assertKey($entitlement);
        if ($days < 1 || $days > 3650) throw new InvalidArgumentException('VIP duration must be between 1 and 3650 days.');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->database->beginTransaction();
        try {
            $lock = $this->database->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            if ($lock->fetchColumn() === false) throw new InvalidArgumentException('User does not exist.');
            $statement = $this->database->prepare(
                'SELECT id,expires_at FROM user_entitlements '
                . 'WHERE user_id=:user_id AND entitlement=:entitlement AND revoked_at IS NULL '
                . 'AND starts_at<=UTC_TIMESTAMP() '
                . 'AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY expires_at IS NULL DESC, expires_at DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['user_id' => $userId, 'entitlement' => $entitlement]);
            $record = $statement->fetch();
            if (is_array($record) && $record['expires_at'] === null) {
                $this->database->commit();
                return 'lifetime';
            }
            $base = is_array($record)
                ? new DateTimeImmutable((string) $record['expires_at'], new DateTimeZone('UTC'))
                : $now;
            $expires = $base->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
            if (is_array($record)) {
                $update = $this->database->prepare(
                    "UPDATE user_entitlements SET expires_at=:expires,plan_key='vip-custom',source='manual',granted_by=:actor,updated_at=UTC_TIMESTAMP() WHERE id=:id"
                );
                $update->execute(['expires' => $expires, 'actor' => $grantedBy, 'id' => $record['id']]);
            } else {
                $insert = $this->database->prepare(
                    "INSERT INTO user_entitlements (user_id,entitlement,plan_key,source,starts_at,expires_at,granted_by,activated_event_at,created_at,updated_at) "
                    . "VALUES (:user_id,:entitlement,'vip-custom','manual',UTC_TIMESTAMP(),:expires,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
                );
                $insert->execute(['user_id' => $userId, 'entitlement' => $entitlement, 'expires' => $expires, 'actor' => $grantedBy]);
            }
            $this->database->commit();
            return $expires;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function replace(
        int $userId,
        string $entitlement,
        ?int $days,
        ?int $grantedBy,
        string $planKey,
        string $source = 'manual',
        ?string $note = null,
    ): void {
        $this->assertKey($entitlement);
        $this->assertPlanKey($planKey);
        $this->assertSource($source);
        if ($days !== null && ($days < 1 || $days > 3650)) {
            throw new InvalidArgumentException('Membership duration must be between 1 and 3650 days.');
        }
        if ($note !== null && mb_strlen($note) > 255) {
            throw new InvalidArgumentException('Membership note must not exceed 255 characters.');
        }

        $ownsTransaction=!$this->database->inTransaction();
        if($ownsTransaction) $this->database->beginTransaction();
        try {
            $lock = $this->database->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            if ($lock->fetchColumn() === false) throw new InvalidArgumentException('User does not exist.');

            $revoke = $this->database->prepare(
                'UPDATE user_entitlements SET revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() '
                . 'WHERE user_id=:user_id AND entitlement=:entitlement AND revoked_at IS NULL '
                . 'AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())'
            );
            $revoke->execute(['user_id' => $userId, 'entitlement' => $entitlement]);

            $expires = $days === null
                ? null
                : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
            $insert = $this->database->prepare(
                'INSERT INTO user_entitlements (user_id,entitlement,plan_key,source,note,starts_at,expires_at,granted_by,activated_event_at,created_at,updated_at) '
                . 'VALUES (:user_id,:entitlement,:plan_key,:source,:note,UTC_TIMESTAMP(),:expires,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'user_id' => $userId,
                'entitlement' => $entitlement,
                'plan_key' => $planKey,
                'source' => $source,
                'note' => $note,
                'expires' => $expires,
                'actor' => $grantedBy,
            ]);
            if($ownsTransaction) $this->database->commit();
        } catch (Throwable $error) {
            if($ownsTransaction&&$this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function extend(
        int $userId,
        string $entitlement,
        int $days,
        int $grantedBy,
        ?string $note = null,
    ): string {
        $this->assertKey($entitlement);
        if($days<1||$days>3650) throw new InvalidArgumentException('Membership extension must be between 1 and 3650 days.');
        if($note!==null&&mb_strlen($note)>255) throw new InvalidArgumentException('Membership note must not exceed 255 characters.');

        $this->database->beginTransaction();
        try {
            $lock=$this->database->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id'=>$userId]);
            if($lock->fetchColumn()===false) throw new InvalidArgumentException('User does not exist.');

            $statement=$this->database->prepare(
                'SELECT id,expires_at FROM user_entitlements WHERE user_id=:user_id AND entitlement=:entitlement '
                . 'AND revoked_at IS NULL AND starts_at<=UTC_TIMESTAMP() '
                . 'AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) '
                . 'ORDER BY expires_at IS NULL DESC,expires_at DESC,id DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['user_id'=>$userId,'entitlement'=>$entitlement]);
            $record=$statement->fetch();
            if(!is_array($record)){
                throw new InvalidArgumentException('Only an active VIP membership can be extended.');
            }
            if($record['expires_at']===null){
                throw new InvalidArgumentException('Lifetime VIP does not need an expiration extension.');
            }

            $base=new DateTimeImmutable((string)$record['expires_at'],new DateTimeZone('UTC'));
            $expires=$base->modify('+'.$days.' days')->format('Y-m-d H:i:s');

            $update=$this->database->prepare(
                'UPDATE user_entitlements SET expires_at=:expires,source=\'manual\',note=COALESCE(:note,note),granted_by=:actor,expired_event_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            );
            $update->execute(['expires'=>$expires,'note'=>$note,'actor'=>$grantedBy,'id'=>$record['id']]);
            $this->database->commit();
            return $expires;
        } catch(Throwable $error){
            if($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function schedule(
        int $userId,
        string $entitlement,
        ?int $days,
        string $startsAt,
        int $grantedBy,
        string $planKey,
        string $source = 'manual',
        ?string $note = null,
    ): void {
        $this->assertKey($entitlement);
        $this->assertPlanKey($planKey);
        $this->assertSource($source);
        if($days!==null&&($days<1||$days>3650)) throw new InvalidArgumentException('Membership duration must be between 1 and 3650 days.');
        if($note!==null&&mb_strlen($note)>255) throw new InvalidArgumentException('Membership note must not exceed 255 characters.');

        $utc=new DateTimeZone('UTC');
        try{$starts=(new DateTimeImmutable($startsAt,$utc))->setTimezone($utc);}
        catch(\Throwable){throw new InvalidArgumentException('Membership start date is invalid.');}
        $now=new DateTimeImmutable('now',$utc);
        if($starts<=$now) throw new InvalidArgumentException('Scheduled membership must start in the future.');
        $expires=$days===null?null:$starts->modify('+'.$days.' days')->format('Y-m-d H:i:s');

        $this->database->beginTransaction();
        try{
            $lock=$this->database->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id'=>$userId]);
            if($lock->fetchColumn()===false) throw new InvalidArgumentException('User does not exist.');

            $current=$this->database->prepare(
                'SELECT expires_at FROM user_entitlements WHERE user_id=:user_id AND entitlement=:entitlement '
                . 'AND revoked_at IS NULL AND starts_at<=UTC_TIMESTAMP() '
                . 'AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) '
                . 'ORDER BY expires_at IS NULL DESC,expires_at DESC,id DESC LIMIT 1 FOR UPDATE'
            );
            $current->execute(['user_id'=>$userId,'entitlement'=>$entitlement]);
            $active=$current->fetch();
            if(is_array($active)){
                if($active['expires_at']===null) throw new InvalidArgumentException('Lifetime VIP cannot have a future overlapping membership.');
                $activeExpires=new DateTimeImmutable((string)$active['expires_at'],$utc);
                if($starts<$activeExpires) throw new InvalidArgumentException('Scheduled membership cannot overlap the active VIP period.');
            }

            $overlap=$this->database->prepare(
                'SELECT COUNT(*) FROM user_entitlements WHERE user_id=:user_id AND entitlement=:entitlement '
                . 'AND revoked_at IS NULL AND starts_at>UTC_TIMESTAMP()'
            );
            $overlap->execute(['user_id'=>$userId,'entitlement'=>$entitlement]);
            if((int)$overlap->fetchColumn()>0) throw new InvalidArgumentException('A future membership is already scheduled for this user.');

            $insert=$this->database->prepare(
                'INSERT INTO user_entitlements (user_id,entitlement,plan_key,source,note,starts_at,expires_at,granted_by,created_at,updated_at) '
                . 'VALUES (:user_id,:entitlement,:plan_key,:source,:note,:starts_at,:expires,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'user_id'=>$userId,'entitlement'=>$entitlement,'plan_key'=>$planKey,'source'=>$source,
                'note'=>$note,'starts_at'=>$starts->format('Y-m-d H:i:s'),'expires'=>$expires,'actor'=>$grantedBy,
            ]);
            $this->database->commit();
        }catch(Throwable $error){
            if($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function cancelScheduled(int $userId, string $entitlement): bool
    {
        $this->assertKey($entitlement);
        $statement=$this->database->prepare(
            'UPDATE user_entitlements SET revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() '
            . 'WHERE user_id=:user_id AND entitlement=:entitlement AND revoked_at IS NULL '
            . 'AND starts_at>UTC_TIMESTAMP()'
        );
        $statement->execute(['user_id'=>$userId,'entitlement'=>$entitlement]);
        return $statement->rowCount()>0;
    }

    public function revoke(int $userId, string $entitlement): void
    {
        $this->assertKey($entitlement);
        $statement = $this->database->prepare(
            'UPDATE user_entitlements SET revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() '
            . 'WHERE user_id=:user_id AND entitlement=:entitlement AND revoked_at IS NULL '
            . 'AND starts_at<=UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())'
        );
        $statement->execute(['user_id' => $userId, 'entitlement' => $entitlement]);
    }

    private function assertKey(string $key): void
    {
        if (! preg_match('/^[a-z][a-z0-9.-]{1,63}$/', $key)) throw new InvalidArgumentException('Invalid entitlement key.');
    }

    private function assertPlanKey(string $key): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]{1,63}$/', $key)) throw new InvalidArgumentException('Invalid membership plan key.');
    }

    private function assertSource(string $source): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $source)) throw new InvalidArgumentException('Invalid entitlement source.');
    }
}
