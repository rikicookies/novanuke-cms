<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Core\Backup\BackupRecoveryCheck;
use NovaNuke\Core\Mail\MailConfigurationCheck;
use NovaNuke\Core\Mail\MailDeliveryAcceptance;
use NovaNuke\Core\Membership\MembershipHealthCheck;
use NovaNuke\Core\Billing\PaymentHealthCheck;
use NovaNuke\Core\Security\AuthorizationAudit;
use NovaNuke\Core\Themes\ThemeDistributionCheck;

final class ReleaseCandidateDeploymentCheck
{
    public function __construct(
        private readonly InstalledSiteHealthCheck $site,
        private readonly ProductionReadiness $production,
        private readonly BackupRecoveryCheck $backups,
        private readonly AuthorizationAudit $authorization,
        private readonly MembershipHealthCheck $membership,
        private readonly PaymentHealthCheck $payments,
        private readonly MailConfigurationCheck $mail,
        private readonly MailDeliveryAcceptance $mailAcceptance,
        private readonly ThemeDistributionCheck $themes,
        private readonly DeploymentSecretCheck $secrets,
    ) {
    }

    /** @return list<array{group:string,name:string,passed:bool,required:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];

        foreach($this->site->run() as $check){
            $checks[]=['group'=>'site','name'=>$check['name'],'passed'=>$check['passed'],'required'=>true,'detail'=>$check['detail']];
        }
        foreach($this->production->run() as $check){
            $checks[]=['group'=>'production','name'=>$check['name'],'passed'=>$check['passed'],'required'=>$check['required'],'detail'=>$check['detail']];
        }
        foreach($this->backups->run() as $check){
            $checks[]=['group'=>'backups','name'=>$check['name'],'passed'=>$check['passed'],'required'=>true,'detail'=>$check['detail']];
        }
        foreach($this->authorization->run() as $check){
            $checks[]=['group'=>'authorization','name'=>$check['label'],'passed'=>$check['passed'],'required'=>true,'detail'=>$check['detail']];
        }
        foreach($this->membership->run() as $check){
            $checks[]=['group'=>'membership','name'=>$check['name'],'passed'=>$check['passed'],'required'=>true,'detail'=>$check['detail']];
        }
        foreach($this->payments->run() as $check){
            $checks[]=['group'=>'payments','name'=>$check['name'],'passed'=>$check['passed'],'required'=>true,'detail'=>$check['detail']];
        }
        foreach($this->mail->run() as $check){
            $checks[]=['group'=>'mail-config','name'=>$check['name'],'passed'=>$check['passed'],'required'=>$check['required'],'detail'=>$check['detail']];
        }
        foreach($this->mailAcceptance->run() as $check){
            $checks[]=['group'=>'mail-delivery','name'=>$check['name'],'passed'=>$check['passed'],'required'=>true,'detail'=>$check['detail']];
        }
        foreach($this->themes->run() as $check){
            $checks[]=['group'=>'themes','name'=>$check['name'],'passed'=>$check['passed'],'required'=>true,'detail'=>$check['detail']];
        }
        foreach($this->secrets->run() as $check){
            $checks[]=['group'=>'secrets','name'=>$check['name'],'passed'=>$check['passed'],'required'=>$check['required'],'detail'=>$check['detail']];
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
}
