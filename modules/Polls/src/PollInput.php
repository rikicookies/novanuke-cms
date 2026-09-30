<?php

declare(strict_types=1);

namespace Modules\Polls\src;

use DateTimeImmutable;use DateTimeZone;use RuntimeException;

final class PollInput
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function poll(array $input):array
    {
        $question=trim(strip_tags((string)($input['question']??'')));if(mb_strlen($question)<5||mb_strlen($question)>300)throw new RuntimeException('Question must contain 5-300 characters.');
        $status=(string)($input['status']??'draft');if(!in_array($status,['draft','active','closed'],true))throw new RuntimeException('Invalid poll status.');
        $options=[];$seen=[];foreach(preg_split('/\R/u',(string)($input['options']??''))?:[] as $line){$option=trim(strip_tags($line));if($option==='')continue;$key=mb_strtolower($option,'UTF-8');if(isset($seen[$key]))throw new RuntimeException('Poll options must be unique.');$seen[$key]=true;$options[]=$option;}
        if(count($options)<2||count($options)>20)throw new RuntimeException('Provide 2-20 unique options.');foreach($options as $option)if(mb_strlen($option)>200)throw new RuntimeException('Poll options cannot exceed 200 characters.');
        $multiple=($input['allow_multiple']??null)==='1';$max=$multiple?max(2,min(count($options),(int)($input['max_selections']??2))):1;
        $starts=$this->date($input['starts_at']??null);$ends=$this->date($input['ends_at']??null);if($starts!==null&&$ends!==null&&$ends<=$starts)throw new RuntimeException('The end date must be later than the start date.');
        return compact('question','status','options')+['allow_multiple'=>$multiple?1:0,'max_selections'=>$max,'starts_at'=>$starts,'ends_at'=>$ends];
    }
    private function date(mixed $value):?string{$value=trim((string)$value);if($value==='')return null;$date=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$value,new DateTimeZone('UTC'));if($date===false||$date->format('Y-m-d\TH:i')!==$value)throw new RuntimeException('Invalid poll date.');return$date->format('Y-m-d H:i:s');}
}
