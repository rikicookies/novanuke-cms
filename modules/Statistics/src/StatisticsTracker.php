<?php

declare(strict_types=1);

namespace Modules\Statistics\src;

use NovaNuke\Core\Http\Request;

final class StatisticsTracker
{
    public function __construct(private readonly StatisticsRepository $statistics,private readonly StatisticsDimensions $dimensions,private readonly string $siteHost){}
    public function track(Request $request):void{if(PHP_SAPI==='cli'||$request->method()!=='GET')return;$path=$request->path();foreach(['/admin','/login','/register','/forgot-password','/reset-password','/verify-email','/messages','/install']as$private)if($path===$private||str_starts_with($path,$private.'/'))return;try{$this->statistics->record($this->dimensions->classify($path,$request->referer(),$request->userAgent(),$this->siteHost));}catch(\PDOException $e){error_log('Statistics aggregation failed: '.$e->getMessage());}}
}
