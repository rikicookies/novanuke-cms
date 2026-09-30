<?php

declare(strict_types=1);

namespace Modules\Statistics\src;

use NovaNuke\Core\Http\Response;use NovaNuke\Core\Settings\SettingsRepository;use NovaNuke\Core\View\ViewRenderer;

final class PublicStatisticsController
{
    public function __construct(private readonly StatisticsRepository $statistics,private readonly SettingsRepository $settings,private readonly ViewRenderer $views){}
    public function index():Response{if(!$this->settings->boolean('statistics.public_enabled',false))return Response::html($this->views->render('@statistics/unavailable.twig'),404);return Response::html($this->views->render('@statistics/index.twig',['summary'=>$this->statistics->summary(),'sections'=>$this->statistics->sections(),'trends'=>$this->statistics->trends(),'top'=>$this->statistics->topContent()]));}
}
