<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

use NovaNuke\Core\Config\ConfigRepository;

final class MailConfigurationCheck
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /** @return list<array{name:string,passed:bool,required:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];
        $mailer=strtolower((string)$this->config->get('mail.mailer','log'));
        $supported=in_array($mailer,['log','smtp'],true);
        $this->add(
            $checks,
            'Mail transport',
            $supported,
            true,
            $supported?"Configured transport: {$mailer}.":"Unsupported MAIL_MAILER value.",
        );

        $from=(string)$this->config->get('mail.from_address','');
        $this->add(
            $checks,
            'Mail from address',
            filter_var($from,FILTER_VALIDATE_EMAIL)!==false,
            $mailer==='smtp',
            $from!==''?"Configured sender: {$from}.":'MAIL_FROM_ADDRESS is empty.',
        );

        $fromName=(string)$this->config->get('mail.from_name','NovaNuke');
        $this->add(
            $checks,
            'Mail from name',
            $fromName!==''&&preg_match('/[\r\n]/',$fromName)!==1,
            true,
            'Sender display name must be non-empty and header-safe.',
        );

        if($mailer==='smtp'){
            try{
                new SmtpConfiguration(
                    (string)$this->config->get('mail.host',''),
                    (int)$this->config->get('mail.port',465),
                    (string)$this->config->get('mail.username',''),
                    (string)$this->config->get('mail.password',''),
                    strtolower((string)$this->config->get('mail.encryption','ssl')),
                    (int)$this->config->get('mail.timeout',15),
                    $from,
                    $fromName,
                );
                $this->add($checks,'SMTP configuration',true,true,'SMTP configuration is structurally valid.');
            }catch(\Throwable $error){
                $this->add($checks,'SMTP configuration',false,true,$error->getMessage());
            }
        }else{
            $this->add(
                $checks,
                'SMTP configuration',
                false,
                false,
                'Log mail is acceptable for development; real SMTP delivery remains unverified.',
            );
        }

        return $checks;
    }

    public function passed(): bool
    {
        foreach($this->run() as $check){
            if($check['required']&&!$check['passed']) return false;
        }
        return true;
    }

    /** @param list<array{name:string,passed:bool,required:bool,detail:string}> $checks */
    private function add(array &$checks,string $name,bool $passed,bool $required,string $detail): void
    {
        $checks[]=compact('name','passed','required','detail');
    }
}
