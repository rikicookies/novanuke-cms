<?php

declare(strict_types=1);

namespace NovaNuke\Core\System;

use NovaNuke\Core\Version;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class ReleaseCandidateChecklist
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return list<array{name:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];

        $smoke=(new DistributionSmokeCheck($this->rootPath))->run();
        $smokePassed=true;
        foreach($smoke as $check) $smokePassed=$smokePassed&&$check['passed'];
        $this->add(
            $checks,
            'Distribution smoke',
            $smokePassed,
            $smokePassed?'Distribution smoke checks passed.':'One or more distribution smoke checks failed.',
        );

        $forbidden=[];
        foreach(['.env','storage/installed.lock'] as $path){
            if(file_exists($this->rootPath.'/'.$path)||is_link($this->rootPath.'/'.$path)) $forbidden[]=$path;
        }
        foreach(glob($this->rootPath.'/.env.*')?:[] as $path){
            if(in_array(basename($path),['.env.example','.env.testing.example'],true)) continue;
            $forbidden[]=str_replace('\\','/',substr($path,strlen($this->rootPath)+1));
        }
        foreach(['*.sql','*.zip','*.tar','*.tar.gz','*.bak'] as $pattern){
            foreach(glob($this->rootPath.'/'.$pattern)?:[] as $path){
                if(!is_file($path)&&!is_link($path)) continue;
                $forbidden[]=str_replace('\\','/',substr($path,strlen($this->rootPath)+1));
            }
        }
        foreach($this->runtimeArtifacts() as $path) $forbidden[]=$path;
        sort($forbidden,SORT_STRING);
        $this->add(
            $checks,
            'Clean source package',
            $forbidden===[],
            $forbidden===[]?'No local environment, installation lock, backup, log or cache artifact is present.':'Remove: '.implode(', ',$forbidden),
        );

        $readme=@file_get_contents($this->rootPath.'/README.md');
        $notes=$this->rootPath.'/docs/RELEASE_NOTES_'.Version::CURRENT.'.md';
        $metadata=is_string($readme)
            &&str_contains($readme,'Current public release: **'.Version::CURRENT.'**')
            &&is_file($notes)
            &&str_contains((string)file_get_contents($notes),Version::CURRENT);
        $this->add(
            $checks,
            'Current release metadata',
            $metadata,
            $metadata?'README and release notes match the current version.':'README/release notes do not match '.Version::CURRENT.'.',
        );

        $requiredDocs=[
            'docs/INSTALLATION.md',
            'docs/UPDATING.md',
            'docs/PRODUCTION.md',
            'docs/PRODUCTION_HARDENING.md',
            'docs/SECURITY_CHECKLIST.md',
            'docs/CLEAN_INSTALL_CHECKLIST.md',
            'docs/KNOWN_ISSUES.md',
            'docs/TESTING.md',
            'docs/RELEASE.md',
                        'docs/RECOVERY.md',
        ];
        $missing=array_values(array_filter(
            $requiredDocs,
            fn(string $path): bool=>!is_file($this->rootPath.'/'.$path),
        ));
        $this->add(
            $checks,
            'Release documentation set',
            $missing===[],
            $missing===[]?'Required installation, upgrade, production, security and QA documentation is present.':'Missing: '.implode(', ',$missing),
        );

        $staleAlpha=is_string($readme)&&str_contains(strtolower($readme),'package remains alpha');
        $this->add(
            $checks,
            'Release status language',
            !$staleAlpha,
            !$staleAlpha?'README does not describe the current beta/RC line as alpha.':'README still contains stale alpha-only release language.',
        );

        return $checks;
    }

    public function passed(): bool
    {
        foreach($this->run() as $check) if(!$check['passed']) return false;
        return true;
    }

    /** @return list<string> */
    private function runtimeArtifacts(): array
    {
        $paths=[];
        $roots=[
            'storage/cache',
            'storage/logs',
            'storage/sessions',
            'storage/private',
            'public/uploads',
        ];
        foreach($roots as $relativeRoot){
            $directory=$this->rootPath.'/'.$relativeRoot;
            if(!is_dir($directory)) continue;
            $iterator=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)
            );
            foreach($iterator as $item){
                if(!$item->isFile()&&!$item->isLink()) continue;
                $relative=str_replace('\\','/',substr($item->getPathname(),strlen($this->rootPath)+1));
                $base=strtolower($item->getBasename());
                if(in_array($base,['.gitkeep','.htaccess'],true)) continue;
                $paths[]=$relative;
            }
        }

        foreach(glob($this->rootPath.'/storage/private/backups/*')?:[] as $file){
            if(!is_file($file)&&!is_link($file)) continue;
            $base=strtolower(basename($file));
            if(in_array($base,['.gitkeep','.htaccess'],true)) continue;
            $relative=str_replace('\\','/',substr($file,strlen($this->rootPath)+1));
            if(!in_array($relative,$paths,true)) $paths[]=$relative;
        }

        return $paths;
    }

    /** @param list<array{name:string,passed:bool,detail:string}> $checks */
    private function add(array &$checks,string $name,bool $passed,string $detail): void
    {
        $checks[]=['name'=>$name,'passed'=>$passed,'detail'=>$detail];
    }
}
