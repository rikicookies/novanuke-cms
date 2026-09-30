<?php

declare(strict_types=1);

namespace NovaNuke\Core\Membership;

use DateTimeImmutable;
use DateTimeZone;

final class MembershipStatusPresenter
{
    /** @param array<string,mixed> $status @return array<string,mixed> */
    public function present(array $status, ?DateTimeImmutable $now = null): array
    {
        $utc=new DateTimeZone('UTC');
        $now=($now??new DateTimeImmutable('now',$utc))->setTimezone($utc);
        $result=$status;

        if(!($status['vip']??false)){
            $result['state']='free';
            $result['label']='Free';
            $result['days_remaining']=null;
            $result['next_change_at']=null;
            return $result;
        }

        if($status['lifetime']??false){
            $result['state']='lifetime';
            $result['label']=$status['name']??'VIP Lifetime';
            $result['days_remaining']=null;
            $result['next_change_at']=null;
            return $result;
        }

        $expires=is_string($status['expires_at']??null)
            ? new DateTimeImmutable($status['expires_at'],$utc)
            : null;
        $seconds=$expires===null?0:max(0,$expires->getTimestamp()-$now->getTimestamp());

        $result['state']=$seconds>0?'active':'expired';
        $result['label']=$status['name']??'VIP';
        $result['days_remaining']=$seconds>0?(int)ceil($seconds/86400):0;
        $result['next_change_at']=$expires?->format('Y-m-d H:i:s');

        return $result;
    }

    /** @param array<string,mixed>|null $scheduled @return array<string,mixed>|null */
    public function scheduled(?array $scheduled, ?DateTimeImmutable $now = null): ?array
    {
        if($scheduled===null) return null;
        $utc=new DateTimeZone('UTC');
        $now=($now??new DateTimeImmutable('now',$utc))->setTimezone($utc);
        if(!is_string($scheduled['starts_at']??null)) return $scheduled;

        $starts=new DateTimeImmutable($scheduled['starts_at'],$utc);
        $seconds=max(0,$starts->getTimestamp()-$now->getTimestamp());
        $scheduled['starts_in_days']=(int)ceil($seconds/86400);
        $scheduled['state']='scheduled';

        return $scheduled;
    }
}
