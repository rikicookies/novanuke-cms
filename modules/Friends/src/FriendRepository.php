<?php

declare(strict_types=1);

namespace Modules\Friends\src;

use PDO;
use RuntimeException;

final class FriendRepository
{
    public function __construct(private readonly PDO $database) {}
    public function acceptedCount(int $userId):int{$s=$this->database->prepare("SELECT COUNT(*) FROM friendships WHERE (user_one_id=:user1 OR user_two_id=:user2) AND status='accepted'");$s->execute(['user1'=>$userId,'user2'=>$userId]);return(int)$s->fetchColumn();}
    private function pair(int $a,int $b):array{return[min($a,$b),max($a,$b)];}
    public function userExists(int $id):bool{$s=$this->database->prepare("SELECT COUNT(*) FROM users WHERE id=:id AND status='active' AND deleted_at IS NULL");$s->execute(['id'=>$id]);return(int)$s->fetchColumn()===1;}
    public function blocked(int $a,int $b):bool{$s=$this->database->prepare('SELECT COUNT(*) FROM friend_blocks WHERE (blocker_user_id=:a1 AND blocked_user_id=:b1) OR (blocker_user_id=:b2 AND blocked_user_id=:a2)');$s->execute(['a1'=>$a,'b1'=>$b,'b2'=>$b,'a2'=>$a]);return(int)$s->fetchColumn()>0;}
    public function state(int $viewer,int $other):array
    {
        $b=$this->database->prepare('SELECT blocker_user_id FROM friend_blocks WHERE (blocker_user_id=:viewer AND blocked_user_id=:other) OR (blocker_user_id=:other2 AND blocked_user_id=:viewer2) LIMIT 1');$b->execute(['viewer'=>$viewer,'other'=>$other,'other2'=>$other,'viewer2'=>$viewer]);$blocker=$b->fetchColumn();
        if($blocker!==false)return['status'=>(int)$blocker===$viewer?'blocked':'unavailable','requested_by'=>null];
        [$one,$two]=$this->pair($viewer,$other);$s=$this->database->prepare('SELECT status,requested_by FROM friendships WHERE user_one_id=:one AND user_two_id=:two');$s->execute(compact('one','two'));$row=$s->fetch();return is_array($row)?$row:['status'=>'none','requested_by'=>null];
    }
    public function request(int $from,int $to):void
    {
        [$one,$two]=$this->pair($from,$to);try{$s=$this->database->prepare("INSERT INTO friendships(user_one_id,user_two_id,requested_by,status,created_at,updated_at) VALUES(:one,:two,:requester,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->execute(['one'=>$one,'two'=>$two,'requester'=>$from]);}catch(\PDOException $e){if($e->getCode()==='23000')throw new RuntimeException('A friendship or request already exists.',0,$e);throw$e;}
    }
    public function accept(int $user,int $other):void{$this->changePending($user,$other,"UPDATE friendships SET status='accepted',updated_at=UTC_TIMESTAMP() WHERE user_one_id=:one AND user_two_id=:two AND requested_by<>:user AND status='pending'");}
    public function decline(int $user,int $other):void{$this->changePending($user,$other,"DELETE FROM friendships WHERE user_one_id=:one AND user_two_id=:two AND requested_by<>:user AND status='pending'");}
    public function remove(int $user,int $other):void{[$one,$two]=$this->pair($user,$other);$s=$this->database->prepare("DELETE FROM friendships WHERE user_one_id=:one AND user_two_id=:two AND status='accepted'");$s->execute(compact('one','two'));if($s->rowCount()!==1)throw new RuntimeException('Friendship not found.');}
    public function block(int $user,int $other):void
    {
        [$one,$two]=$this->pair($user,$other);$this->database->beginTransaction();try{$this->database->prepare('INSERT IGNORE INTO friend_blocks(blocker_user_id,blocked_user_id,created_at) VALUES(:user,:other,UTC_TIMESTAMP())')->execute(compact('user','other'));$this->database->prepare('DELETE FROM friendships WHERE user_one_id=:one AND user_two_id=:two')->execute(compact('one','two'));$this->database->commit();}catch(\Throwable $e){if($this->database->inTransaction())$this->database->rollBack();throw$e;}
    }
    public function unblock(int $user,int $other):void{$this->database->prepare('DELETE FROM friend_blocks WHERE blocker_user_id=:user AND blocked_user_id=:other')->execute(compact('user','other'));}
    public function lists(int $user):array
    {
        $friends=$this->people("SELECT u.id,u.username,p.display_name,p.avatar_path FROM friendships f INNER JOIN users u ON u.id=IF(f.user_one_id=:user1,f.user_two_id,f.user_one_id) LEFT JOIN user_profiles p ON p.user_id=u.id WHERE (f.user_one_id=:user2 OR f.user_two_id=:user3) AND f.status='accepted' AND u.status='active' AND u.deleted_at IS NULL ORDER BY COALESCE(p.display_name,u.username)",['user1'=>$user,'user2'=>$user,'user3'=>$user]);
        $received=$this->people("SELECT u.id,u.username,p.display_name,p.avatar_path FROM friendships f INNER JOIN users u ON u.id=f.requested_by LEFT JOIN user_profiles p ON p.user_id=u.id WHERE (f.user_one_id=:user1 OR f.user_two_id=:user2) AND f.requested_by<>:user3 AND f.status='pending' ORDER BY f.created_at",['user1'=>$user,'user2'=>$user,'user3'=>$user]);
        $sent=$this->people("SELECT u.id,u.username,p.display_name,p.avatar_path FROM friendships f INNER JOIN users u ON u.id=IF(f.user_one_id=:user1,f.user_two_id,f.user_one_id) LEFT JOIN user_profiles p ON p.user_id=u.id WHERE (f.user_one_id=:user2 OR f.user_two_id=:user3) AND f.requested_by=:user4 AND f.status='pending' ORDER BY f.created_at",['user1'=>$user,'user2'=>$user,'user3'=>$user,'user4'=>$user]);
        $blocked=$this->people('SELECT u.id,u.username,p.display_name,p.avatar_path FROM friend_blocks b INNER JOIN users u ON u.id=b.blocked_user_id LEFT JOIN user_profiles p ON p.user_id=u.id WHERE b.blocker_user_id=:user ORDER BY u.username',['user'=>$user]);return compact('friends','received','sent','blocked');
    }
    private function changePending(int $user,int $other,string $sql):void{[$one,$two]=$this->pair($user,$other);$s=$this->database->prepare($sql);$s->execute(compact('one','two','user'));if($s->rowCount()!==1)throw new RuntimeException('Pending request not found.');}
    private function people(string $sql,array $params):array{$s=$this->database->prepare($sql);$s->execute($params);return$s->fetchAll();}
}
