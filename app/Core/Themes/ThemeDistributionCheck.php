<?php

declare(strict_types=1);

namespace NovaNuke\Core\Themes;

use NovaNuke\Core\Version;

final class ThemeDistributionCheck
{
    public function __construct(private readonly string $themesPath)
    {
    }

    /** @return list<array{name:string,passed:bool,detail:string}> */
    public function run(): array
    {
        $checks=[];
        try{
            $themes=(new ThemeDetector($this->themesPath))->detect();
        }catch(\Throwable $error){
            return [[
                'name'=>'Theme manifests',
                'passed'=>false,
                'detail'=>$error->getMessage(),
            ]];
        }

        $this->add(
            $checks,
            'Theme manifests',
            $themes!==[],
            $themes!==[]?count($themes).' bundled theme(s) detected.':'No bundled themes were detected.',
        );

        foreach($themes as $slug=>$manifest){
            $compatible=version_compare(Version::CURRENT,$manifest->cmsMinVersion,'>=');
            $this->add(
                $checks,
                "Theme {$slug} compatibility",
                $compatible,
                $compatible
                    ?"Compatible with NovaNuke ".Version::CURRENT.'.'
                    :"Requires NovaNuke {$manifest->cmsMinVersion} or newer.",
            );

            foreach($manifest->layouts as $layout){
                $paths=[
                    $manifest->path.'/layouts/'.$layout.'.twig',
                    $manifest->path.'/templates/layouts/'.$layout.'.twig',
                ];
                $exists=false;
                foreach($paths as $path){
                    if(is_file($path)&&!is_link($path)){
                        $exists=true;
                        break;
                    }
                }
                $this->add(
                    $checks,
                    "Theme {$slug} layout {$layout}",
                    $exists,
                    $exists?'Layout template is present.':'Declared layout template is missing.',
                );
            }

            if($manifest->screenshot!==''){
                $path=$manifest->path.'/'.$manifest->screenshot;
                $ok=is_file($path)&&!is_link($path);
                $this->add(
                    $checks,
                    "Theme {$slug} screenshot",
                    $ok,
                    $ok?'Screenshot is present.':'Declared screenshot is missing.',
                );
            }

            $assets=$manifest->path.'/assets';
            $this->add(
                $checks,
                "Theme {$slug} assets",
                is_dir($assets)&&!is_link($assets),
                is_dir($assets)&&!is_link($assets)?'Asset directory is present.':'Theme asset directory is missing or unsafe.',
            );
        }

        return $checks;
    }

    public function passed(): bool
    {
        foreach($this->run() as $check) if(!$check['passed']) return false;
        return true;
    }

    /** @param list<array{name:string,passed:bool,detail:string}> $checks */
    private function add(array &$checks,string $name,bool $passed,string $detail): void
    {
        $checks[]=['name'=>$name,'passed'=>$passed,'detail'=>$detail];
    }
}
