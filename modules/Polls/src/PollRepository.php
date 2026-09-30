<?php

declare(strict_types=1);

namespace Modules\Polls\src;

use PDO;use RuntimeException;

final class PollRepository
{
    public function __construct(private readonly PDO $database){}
    public function all():array{return$this->database->query('SELECT p.*,(SELECT COUNT(*) FROM poll_votes v WHERE v.poll_id=p.id) AS vote_count FROM polls p ORDER BY p.created_at DESC,p.id DESC')->fetchAll();}
    public function find(int $id):?array{$s=$this->database->prepare('SELECT * FROM polls WHERE id=:id');$s->execute(compact('id'));$poll=$s->fetch();if(!is_array($poll))return null;$poll['options']=$this->options($id);return$poll;}
    public function active():?array{$s=$this->database->query("SELECT id FROM polls WHERE status='active' AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at>=UTC_TIMESTAMP()) ORDER BY starts_at DESC,id DESC LIMIT 1");$id=$s->fetchColumn();return$id===false?null:$this->withResults((int)$id);}
    public function publicPoll(int $id):?array{$s=$this->database->prepare("SELECT id FROM polls WHERE id=:id AND status<>'draft'");$s->execute(compact('id'));return$s->fetchColumn()===false?null:$this->withResults($id);}
    public function save(?int $id,array $data,int $actor):int
    {
        $options=$data['options'];unset($data['options']);$this->database->beginTransaction();
        try{$voteCount=0;if($id===null){$data['created_by']=$actor;$sql='INSERT INTO polls (question,status,allow_multiple,max_selections,starts_at,ends_at,created_by,created_at,updated_at) VALUES (:question,:status,:allow_multiple,:max_selections,:starts_at,:ends_at,:created_by,UTC_TIMESTAMP(),UTC_TIMESTAMP())';}
            else{$existing=$this->find($id);if($existing===null)throw new RuntimeException('Poll not found.');$count=$this->database->prepare('SELECT COUNT(*) FROM poll_votes WHERE poll_id=:id');$count->execute(compact('id'));$voteCount=(int)$count->fetchColumn();if($voteCount>0&&array_column($existing['options'],'label')!==$options)throw new RuntimeException('Options cannot be changed after voting begins.');$data['id']=$id;$sql='UPDATE polls SET question=:question,status=:status,allow_multiple=:allow_multiple,max_selections=:max_selections,starts_at=:starts_at,ends_at=:ends_at,updated_at=UTC_TIMESTAMP() WHERE id=:id';}
            $this->database->prepare($sql)->execute($data);$pollId=$id??(int)$this->database->lastInsertId();
            if($id===null||$voteCount===0){$this->database->prepare('DELETE FROM poll_options WHERE poll_id=:id')->execute(['id'=>$pollId]);$insert=$this->database->prepare('INSERT INTO poll_options (poll_id,label,sort_order) VALUES (:poll,:label,:sort)');foreach($options as $sort=>$label)$insert->execute(['poll'=>$pollId,'label'=>$label,'sort'=>$sort]);}
            $this->database->commit();return$pollId;
        }catch(\Throwable $e){if($this->database->inTransaction())$this->database->rollBack();throw$e;}
    }
    public function delete(int $id):void{$s=$this->database->prepare('DELETE FROM polls WHERE id=:id');$s->execute(compact('id'));if($s->rowCount()!==1)throw new RuntimeException('Poll not found.');}
    public function hasVoted(int $poll,string $key):bool{$s=$this->database->prepare('SELECT COUNT(*) FROM poll_votes WHERE poll_id=:poll AND voter_key=:key');$s->execute(compact('poll','key'));return(int)$s->fetchColumn()>0;}
    public function vote(int $poll,array $choices,?int $user,string $key):void
    {
        $this->database->beginTransaction();try{$s=$this->database->prepare("SELECT allow_multiple,max_selections FROM polls WHERE id=:poll AND status='active' AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at>=UTC_TIMESTAMP()) FOR UPDATE");$s->execute(compact('poll'));$rules=$s->fetch();if(!is_array($rules))throw new RuntimeException('This poll is not accepting votes.');
            $choices=array_values(array_unique(array_map('intval',$choices)));$maximum=(int)$rules['max_selections'];if($choices===[]||count($choices)>$maximum||(!(bool)$rules['allow_multiple']&&count($choices)!==1))throw new RuntimeException('Select the allowed number of options.');
            $marks=implode(',',array_fill(0,count($choices),'?'));$valid=$this->database->prepare("SELECT id FROM poll_options WHERE poll_id=? AND id IN ({$marks})");$valid->execute(array_merge([$poll],$choices));if(count($valid->fetchAll(PDO::FETCH_COLUMN))!==count($choices))throw new RuntimeException('One or more options are invalid.');
            $this->database->prepare('INSERT INTO poll_votes (poll_id,user_id,voter_key,voted_at) VALUES (:poll,:user,:key,UTC_TIMESTAMP())')->execute(compact('poll','user','key'));$vote=(int)$this->database->lastInsertId();$insert=$this->database->prepare('INSERT INTO poll_vote_choices (vote_id,option_id) VALUES (:vote,:option)');foreach($choices as $option)$insert->execute(compact('vote','option'));$this->database->commit();
        }catch(\PDOException $e){if($this->database->inTransaction())$this->database->rollBack();if($e->getCode()==='23000')throw new RuntimeException('You have already voted in this poll.',0,$e);throw$e;}catch(\Throwable $e){if($this->database->inTransaction())$this->database->rollBack();throw$e;}
    }
    private function options(int $poll):array{$s=$this->database->prepare('SELECT id,label,sort_order FROM poll_options WHERE poll_id=:poll ORDER BY sort_order,id');$s->execute(compact('poll'));return$s->fetchAll();}
    private function withResults(int $id):array{$poll=$this->find($id);$s=$this->database->prepare('SELECT o.id,o.label,o.sort_order,COUNT(c.vote_id) AS votes FROM poll_options o LEFT JOIN poll_vote_choices c ON c.option_id=o.id WHERE o.poll_id=:id GROUP BY o.id,o.label,o.sort_order ORDER BY o.sort_order,o.id');$s->execute(compact('id'));$poll['options']=$s->fetchAll();$total=$this->database->prepare('SELECT COUNT(*) FROM poll_votes WHERE poll_id=:id');$total->execute(compact('id'));$poll['total_votes']=(int)$total->fetchColumn();$now=gmdate('Y-m-d H:i:s');$poll['accepting']=$poll['status']==='active'&&($poll['starts_at']===null||$poll['starts_at']<=$now)&&($poll['ends_at']===null||$poll['ends_at']>=$now);return$poll;}
}
