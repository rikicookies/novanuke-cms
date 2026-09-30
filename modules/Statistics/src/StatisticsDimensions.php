<?php

declare(strict_types=1);

namespace Modules\Statistics\src;

final class StatisticsDimensions
{
    /** @return array{section:string,referrer:string,browser:string,device:string} */
    public function classify(string $path,string $referer,string $userAgent,string $siteHost):array
    {
        $first=strtolower(explode('/',trim($path,'/'))[0]??'');$allowed=['news','downloads','pages','search','polls','links','users','statistics'];$section=$first===''?'home':(in_array($first,$allowed,true)?$first:'other');
        $host=strtolower((string)parse_url($referer,PHP_URL_HOST));$siteHost=strtolower($siteHost);if($host===''||$host===$siteHost||$host==='www.'.$siteHost||'www.'.$host===$siteHost)$host='direct';elseif($host==='localhost'||filter_var($host,FILTER_VALIDATE_IP)||strlen($host)>190||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$host))$host='other';else$host=preg_replace('/^www\./','',$host)?:'other';
        $ua=strtolower($userAgent);$device=str_contains($ua,'bot')||str_contains($ua,'spider')?'bot':(str_contains($ua,'ipad')||str_contains($ua,'tablet')?'tablet':(str_contains($ua,'mobile')||str_contains($ua,'iphone')||str_contains($ua,'android')?'mobile':'desktop'));
        $browser=str_contains($ua,'edg/')?'edge':(str_contains($ua,'firefox/')?'firefox':(str_contains($ua,'chrome/')?'chrome':(str_contains($ua,'safari/')?'safari':'other')));
        return['section'=>$section,'referrer'=>$host,'browser'=>$browser,'device'=>$device];
    }
}
