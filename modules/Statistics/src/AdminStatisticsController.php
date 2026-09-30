<?php

declare(strict_types=1);

namespace Modules\Statistics\src;

use NovaNuke\Auth\AuthManager;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\I18n\Translator;use NovaNuke\Core\Logging\ActivityLogger;use NovaNuke\Core\Security\AuthorizationService;use NovaNuke\Core\Security\CsrfTokenManager;use NovaNuke\Core\Security\SessionManager;use NovaNuke\Core\Settings\SettingsRepository;use NovaNuke\Core\View\ViewRenderer;

final class AdminStatisticsController
{
    public function __construct(private readonly StatisticsRepository $statistics,private readonly SettingsRepository $settings,private readonly AuthManager $auth,private readonly AuthorizationService $authorization,private readonly ActivityLogger $activity,private readonly CsrfTokenManager $csrf,private readonly SessionManager $session,private readonly ViewRenderer $views,private readonly Translator $translator){}
    public function index():Response{if($g=$this->guard('statistics.view-admin'))return$g;return Response::html($this->views->render('@statistics/admin/index.twig',['summary'=>$this->statistics->summary(),'trends'=>$this->statistics->trends(),'sections'=>$this->statistics->sections(),'referrers'=>$this->statistics->referrers(),'devices'=>$this->statistics->devices(),'browsers'=>$this->statistics->browsers(),'top'=>$this->statistics->topContent(),'activity'=>$this->statistics->recentActivity(),'collection_enabled'=>$this->settings->boolean('statistics.collection_enabled',true),'public_enabled'=>$this->settings->boolean('statistics.public_enabled',false),'can_manage'=>$this->allows('statistics.manage'),'csrf_token'=>$this->csrf->token(),'message'=>$this->session->pull('statistics.message')]));}
    public function settings(Request $r):Response{if($g=$this->guard('statistics.manage'))return$g;if(!$this->csrf->validate($r->input('_token')))return Response::html($this->translator->translate('statistics::error.csrf'),419);$collection=$r->input('collection_enabled')==='1';$public=$r->input('public_enabled')==='1';$this->settings->setBoolean('statistics.collection_enabled',$collection,'statistics');$this->settings->setBoolean('statistics.public_enabled',$public,'statistics');$u=$this->auth->user();$this->activity->log((int)$u['id'],'statistics.settings.updated','settings','statistics',['collection'=>$collection,'public'=>$public],$r->ip());$this->session->put('statistics.message',$this->translator->translate('statistics::message.saved'));return Response::redirect('/admin/statistics',303);}
    private function guard(string $permission):?Response{$u=$this->auth->user();if($u===null)return Response::redirect('/login');return$this->authorization->allows((int)$u['id'],$permission)?null:Response::html($this->translator->translate('statistics::error.forbidden'),403);}private function allows(string $permission):bool{$u=$this->auth->user();return$u!==null&&$this->authorization->allows((int)$u['id'],$permission);}
}
