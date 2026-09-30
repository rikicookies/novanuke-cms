<?php

declare(strict_types=1);

namespace Modules\Friends\src;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Social\FriendAccepted;
use NovaNuke\Core\Social\FriendRequested;
use RuntimeException;

final class FriendService
{
    public function __construct(private readonly FriendRepository $repository,private readonly EventDispatcher $events) {}
    public function request(int $from,int $to):void{$this->valid($from,$to);if($this->repository->blocked($from,$to))throw new RuntimeException('Friend requests are unavailable between these users.');$this->repository->request($from,$to);$this->events->dispatch(\NovaNuke\Core\Events\EventName::FRIEND_REQUESTED,new FriendRequested($to,$from));}
    public function accept(int $user,int $other):void{$this->valid($user,$other);if($this->repository->blocked($user,$other))throw new RuntimeException('Friend requests are unavailable between these users.');$this->repository->accept($user,$other);$this->events->dispatch(\NovaNuke\Core\Events\EventName::FRIEND_ACCEPTED,new FriendAccepted($other,$user));}
    public function decline(int $user,int $other):void{$this->repository->decline($user,$other);}
    public function remove(int $user,int $other):void{$this->repository->remove($user,$other);}
    public function block(int $user,int $other):void{$this->valid($user,$other);$this->repository->block($user,$other);}
    private function valid(int $user,int $other):void{if($user===$other)throw new RuntimeException('You cannot perform this action on yourself.');if(!$this->repository->userExists($other))throw new RuntimeException('User not found.');}
}
