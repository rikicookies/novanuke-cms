<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Core\Config\ConfigRepository;

final class DeploymentSecretCheck
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /** @return list<array{name:string,passed:bool,required:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];
        $key=(string)$this->config->get('app.key','');
        $keyBytes=null;
        if(str_starts_with($key,'base64:')){
            $decoded=base64_decode(substr($key,7),true);
            if(is_string($decoded)) $keyBytes=strlen($decoded);
        }
        $this->add($checks,'Application key entropy',$keyBytes!==null&&$keyBytes>=32,true,
            $keyBytes!==null?"Decoded APP_KEY length: {$keyBytes} bytes.":'APP_KEY must be valid base64 with at least 32 decoded bytes.');

        $dbPassword=(string)$this->config->get('database.password','');
        $environment=(string)$this->config->get('app.environment','development');
        $this->add($checks,'Database password',$environment!=='production'||$dbPassword!=='',true,
            $environment==='production'&&$dbPassword===''?'Production DB_PASSWORD must not be empty.':'Database credential policy is acceptable for this environment.');

        $mailer=(string)$this->config->get('mail.mailer','log');
        $smtpPassword=(string)$this->config->get('mail.password','');
        $this->add($checks,'SMTP credential',$mailer!=='smtp'||$smtpPassword!=='',true,
            $mailer==='smtp'&&$smtpPassword===''?'SMTP transport requires a non-empty MAIL_PASSWORD.':'SMTP credential policy is acceptable.');

        return $checks;
    }

    public function passed(): bool
    {
        foreach($this->run() as $check) if($check['required']&&!$check['passed']) return false;
        return true;
    }

    /** @param list<array{name:string,passed:bool,required:bool,detail:string}> $checks */
    private function add(array &$checks,string $name,bool $passed,bool $required,string $detail): void
    {
        $checks[]=compact('name','passed','required','detail');
    }
}
