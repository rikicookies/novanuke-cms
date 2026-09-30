<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Core\Database\MigrationStatus;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\Version;

final class InstalledSiteHealthCheck
{
    public function __construct(
        private readonly string $rootPath,
        private readonly SettingsRepository $settings,
        private readonly MigrationStatus $migrations,
    ) {
    }

    /** @return list<array{name:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];

        $env=$this->rootPath.'/.env';
        $envOk=is_file($env)&&!is_link($env);
        $this->add(
            $checks,
            'Environment file',
            $envOk,
            $envOk?'.env exists as a regular file.':'.env is missing or unsafe.',
        );

        $lock=$this->rootPath.'/storage/installed.lock';
        $lockData=$this->readLock($lock);
        $this->add(
            $checks,
            'Installation lock',
            $lockData!==null,
            $lockData!==null?'Installation lock is valid.':'storage/installed.lock is missing or malformed.',
        );

        $recorded=$this->settings->string('system.core_version','');
        $versionOk=$recorded===Version::CURRENT;
        $this->add(
            $checks,
            'Recorded core version',
            $versionOk,
            $versionOk
                ?"Recorded version matches ".Version::CURRENT.'.'
                :"Recorded {$recorded}; running ".Version::CURRENT.'. Complete the supported upgrade before release validation.',
        );

        $status=$this->migrations->inspect();
        $recoveryTotal=(int)($status['recovery_total']??0);
        $migrationsOk=(int)$status['pending_total']===0&&(int)$status['missing_total']===0&&$recoveryTotal===0;
        $this->add(
            $checks,
            'Core and module migrations',
            $migrationsOk,
            $migrationsOk
                ?'No pending or missing migration files.'
                :'Pending '.(int)$status['pending_total'].'; missing '.(int)$status['missing_total'].'; recovery required '.$recoveryTotal.'.',
        );

        $modulesOk=(int)$status['module_updates_total']===0;
        $this->add(
            $checks,
            'Installed module versions',
            $modulesOk,
            $modulesOk
                ?'No installed module updates remain.'
                :(int)$status['module_updates_total'].' installed module update(s) remain.',
        );

        foreach([
            'storage/cache',
            'storage/logs',
            'storage/sessions',
            'storage/private',
            'storage/private/downloads',
            'storage/private/backups',
            'storage/private/avatars',
            'public/uploads',
        ] as $directory){
            $path=$this->rootPath.'/'.$directory;
            $ok=is_dir($path)&&is_writable($path);
            $this->add(
                $checks,
                "Runtime directory {$directory}",
                $ok,
                $ok?'Available and writable.':'Missing or not writable.',
            );
        }

        return $checks;
    }

    /** @return array<string,mixed>|null */
    private function readLock(string $path): ?array
    {
        if(!is_file($path)||is_link($path)) return null;
        $raw=@file_get_contents($path);
        if(!is_string($raw)||trim($raw)==='') return null;
        try{
            $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        }catch(\Throwable){
            return null;
        }
        if(!is_array($data)) return null;
        if(!isset($data['version'])||!is_string($data['version'])||trim($data['version'])==='') return null;
        if(!isset($data['installed_at'])||!is_string($data['installed_at'])||trim($data['installed_at'])==='') return null;
        return $data;
    }

    /** @param list<array{name:string,passed:bool,detail:string}> $checks */
    private function add(array &$checks,string $name,bool $passed,string $detail): void
    {
        $checks[]=['name'=>$name,'passed'=>$passed,'detail'=>$detail];
    }
}
