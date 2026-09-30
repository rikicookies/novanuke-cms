<?php

declare(strict_types=1);

namespace Modules\PrivateMessages\src;

use PDO;
use RuntimeException;

final class PrivateMessageRepository
{
    public function __construct(private readonly PDO $database) {}

    public function userByUsername(string $username): ?array
    {
        $s=$this->database->prepare("SELECT id,username FROM users WHERE username=:username AND status='active' AND deleted_at IS NULL LIMIT 1");
        $s->execute(['username'=>$username]); $user=$s->fetch(); return is_array($user)?$user:null;
    }

    public function blocked(int $left, int $right): bool
    {
        $s=$this->database->prepare('SELECT COUNT(*) FROM private_message_blocks WHERE (blocker_user_id=:left1 AND blocked_user_id=:right1) OR (blocker_user_id=:right2 AND blocked_user_id=:left2)');
        $s->execute(['left1'=>$left,'right1'=>$right,'right2'=>$right,'left2'=>$left]); return (int)$s->fetchColumn()>0;
    }

    public function create(int $sender, int $recipient, string $subject, string $body, string $bodyFormat = 'markdown'): int
    {
        return $this->createWithMessage($sender, $recipient, $subject, $body, $bodyFormat)['conversation_id'];
    }

    /** @return array{conversation_id:int,message_id:int} */
    public function createWithMessage(int $sender, int $recipient, string $subject, string $body, string $bodyFormat = 'markdown'): array
    {
        $this->database->beginTransaction();
        try {
            $this->database->prepare('INSERT INTO private_conversations (subject,created_by,last_message_at,created_at) VALUES (:subject,:sender,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(compact('subject','sender'));
            $id=(int)$this->database->lastInsertId();
            $participant=$this->database->prepare('INSERT INTO private_conversation_participants (conversation_id,user_id) VALUES (:conversation,:user)');
            $participant->execute(['conversation'=>$id,'user'=>$sender]); $participant->execute(['conversation'=>$id,'user'=>$recipient]);
            $message=$this->insertMessage($id,$sender,$body,$bodyFormat);
            $this->database->prepare('UPDATE private_conversation_participants SET last_read_message_id=:message WHERE conversation_id=:conversation AND user_id=:user')->execute(['message'=>$message,'conversation'=>$id,'user'=>$sender]);
            $this->database->commit(); return ['conversation_id'=>$id,'message_id'=>$message];
        } catch (\Throwable $e) { if($this->database->inTransaction())$this->database->rollBack(); throw $e; }
    }

    public function reply(int $conversation, int $sender, string $body, string $bodyFormat = 'markdown'): int
    {
        $other=$this->otherParticipant($conversation,$sender); if($other===null)throw new RuntimeException('Conversation not found.');
        $this->database->beginTransaction();
        try {
            $message=$this->insertMessage($conversation,$sender,$body,$bodyFormat);
            $this->database->prepare('UPDATE private_conversations SET last_message_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$conversation]);
            $this->database->prepare('UPDATE private_conversation_participants SET deleted_at=NULL WHERE conversation_id=:id')->execute(['id'=>$conversation]);
            $this->database->prepare('UPDATE private_conversation_participants SET last_read_message_id=:message WHERE conversation_id=:conversation AND user_id=:user')->execute(['message'=>$message,'conversation'=>$conversation,'user'=>$sender]);
            $this->database->commit(); return $message;
        } catch (\Throwable $e) { if($this->database->inTransaction())$this->database->rollBack(); throw $e; }
    }

    public function inbox(int $user): array
    {
        $s=$this->database->prepare("SELECT c.id,c.subject,c.last_message_at,u.username AS other_username,MAX(m.id)>COALESCE(p.last_read_message_id,0) AS unread FROM private_conversation_participants p INNER JOIN private_conversations c ON c.id=p.conversation_id INNER JOIN private_conversation_participants op ON op.conversation_id=c.id AND op.user_id<>p.user_id INNER JOIN users u ON u.id=op.user_id INNER JOIN private_messages m ON m.conversation_id=c.id WHERE p.user_id=:user AND p.deleted_at IS NULL GROUP BY c.id,c.subject,c.last_message_at,u.username,p.last_read_message_id ORDER BY c.last_message_at DESC,c.id DESC");
        $s->execute(compact('user')); return $s->fetchAll();
    }

    public function sent(int $user): array
    {
        $s=$this->database->prepare('SELECT m.id,m.body,m.body_format,m.created_at,c.id AS conversation_id,c.subject,u.username AS recipient FROM private_messages m INNER JOIN private_conversations c ON c.id=m.conversation_id INNER JOIN private_conversation_participants mine ON mine.conversation_id=c.id AND mine.user_id=m.sender_id INNER JOIN private_conversation_participants p ON p.conversation_id=c.id AND p.user_id<>m.sender_id INNER JOIN users u ON u.id=p.user_id WHERE m.sender_id=:user AND mine.deleted_at IS NULL ORDER BY m.created_at DESC,m.id DESC LIMIT 100');
        $s->execute(compact('user')); return $s->fetchAll();
    }

    public function conversation(int $id,int $user): ?array
    {
        $other=$this->otherParticipant($id,$user); if($other===null)return null;
        $s=$this->database->prepare('SELECT m.id,m.sender_id,m.body,m.body_format,m.created_at,u.username FROM private_messages m INNER JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=:id ORDER BY m.id'); $s->execute(compact('id'));
        $messages=$s->fetchAll(); $last=$messages===[]?null:(int)end($messages)['id'];
        if($last!==null)$this->database->prepare('UPDATE private_conversation_participants SET last_read_message_id=:last WHERE conversation_id=:id AND user_id=:user')->execute(compact('last','id','user'));
        $c=$this->database->prepare('SELECT subject FROM private_conversations WHERE id=:id');$c->execute(compact('id'));
        return ['id'=>$id,'subject'=>(string)$c->fetchColumn(),'other'=>$other,'messages'=>$messages];
    }

    public function deleteFor(int $id,int $user): void
    {
        $s=$this->database->prepare('UPDATE private_conversation_participants SET deleted_at=UTC_TIMESTAMP() WHERE conversation_id=:id AND user_id=:user AND deleted_at IS NULL');$s->execute(compact('id','user'));
        if($s->rowCount()!==1)throw new RuntimeException('Conversation not found.');
    }

    public function block(int $user,int $blocked): void
    {
        if($user===$blocked)throw new RuntimeException('You cannot block yourself.');
        $check=$this->database->prepare("SELECT COUNT(*) FROM users WHERE id=:id AND status='active' AND deleted_at IS NULL");$check->execute(['id'=>$blocked]);if((int)$check->fetchColumn()!==1)throw new RuntimeException('User was not found.');
        $this->database->prepare('INSERT IGNORE INTO private_message_blocks (blocker_user_id,blocked_user_id,created_at) VALUES (:user,:blocked,UTC_TIMESTAMP())')->execute(compact('user','blocked'));
    }

    public function unblock(int $user,int $blocked): void { $this->database->prepare('DELETE FROM private_message_blocks WHERE blocker_user_id=:user AND blocked_user_id=:blocked')->execute(compact('user','blocked')); }
    public function blocks(int $user): array { $s=$this->database->prepare('SELECT u.id,u.username,b.created_at FROM private_message_blocks b INNER JOIN users u ON u.id=b.blocked_user_id WHERE b.blocker_user_id=:user ORDER BY u.username');$s->execute(compact('user'));return $s->fetchAll(); }

    public function report(int $message,int $reporter,string $reason): void
    {
        $s=$this->database->prepare('SELECT sender_id FROM private_messages m INNER JOIN private_conversation_participants p ON p.conversation_id=m.conversation_id WHERE m.id=:message AND p.user_id=:reporter AND p.deleted_at IS NULL');$s->execute(compact('message','reporter'));$sender=$s->fetchColumn();
        if($sender===false || (int)$sender===$reporter)throw new RuntimeException('Message cannot be reported.');
        try{$this->database->prepare("INSERT INTO private_message_reports (message_id,reporter_user_id,reason,status,created_at) VALUES (:message,:reporter,:reason,'open',UTC_TIMESTAMP())")->execute(compact('message','reporter','reason'));}
        catch(\PDOException $e){if($e->getCode()==='23000')throw new RuntimeException('You already reported this message.',0,$e);throw $e;}
    }

    public function reports(): array { return $this->database->query("SELECT r.id,r.reason,r.created_at,m.body,s.username AS sender,u.username AS reporter FROM private_message_reports r INNER JOIN private_messages m ON m.id=r.message_id INNER JOIN users s ON s.id=m.sender_id INNER JOIN users u ON u.id=r.reporter_user_id WHERE r.status='open' ORDER BY r.created_at DESC")->fetchAll(); }
    public function resolve(int $id): void { $s=$this->database->prepare("UPDATE private_message_reports SET status='resolved',resolved_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");$s->execute(compact('id'));if($s->rowCount()!==1)throw new RuntimeException('Open report not found.'); }

    private function insertMessage(int $conversation,int $sender,string $body,string $bodyFormat): int { $this->database->prepare('INSERT INTO private_messages (conversation_id,sender_id,body,body_format,created_at) VALUES (:conversation,:sender,:body,:body_format,UTC_TIMESTAMP())')->execute(['conversation'=>$conversation,'sender'=>$sender,'body'=>$body,'body_format'=>$bodyFormat]);return (int)$this->database->lastInsertId(); }
    private function otherParticipant(int $conversation,int $user): ?array { $s=$this->database->prepare('SELECT u.id,u.username FROM private_conversation_participants mine INNER JOIN private_conversation_participants other ON other.conversation_id=mine.conversation_id AND other.user_id<>mine.user_id INNER JOIN users u ON u.id=other.user_id WHERE mine.conversation_id=:conversation AND mine.user_id=:user AND mine.deleted_at IS NULL LIMIT 1');$s->execute(compact('conversation','user'));$other=$s->fetch();return is_array($other)?$other:null; }
}
