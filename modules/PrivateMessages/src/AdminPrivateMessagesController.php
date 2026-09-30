<?php

declare(strict_types=1);

namespace Modules\PrivateMessages\src;

use NovaNuke\Auth\AuthManager;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\I18n\Translator;use NovaNuke\Core\Logging\ActivityLogger;use NovaNuke\Core\Security\AuthorizationService;use NovaNuke\Core\Security\CsrfTokenManager;use NovaNuke\Core\Security\SessionManager;use NovaNuke\Core\View\ViewRenderer;use RuntimeException;

final class AdminPrivateMessagesController
{
    public function __construct(private readonly PrivateMessageRepository $repository,private readonly AuthManager $auth,private readonly AuthorizationService $authorization,private readonly ActivityLogger $activity,private readonly CsrfTokenManager $csrf,private readonly SessionManager $session,private readonly ViewRenderer $views,private readonly Translator $translator){}
    public function index():Response{if($g=$this->guard())return$g;return Response::html($this->views->render('@private-messages/admin/index.twig',['reports'=>$this->repository->reports(),'csrf_token'=>$this->csrf->token(),'message'=>$this->session->pull('private-messages.admin.message'),'error'=>$this->session->pull('private-messages.admin.error')]));}
    public function resolve(Request $r):Response{if($g=$this->guard())return$g;if(!$this->csrf->validate($r->input('_token')))return Response::html($this->translator->translate('private-messages::error.csrf'),419);try{$id=$this->id($r->attribute('id'));$this->repository->resolve($id);$u=$this->auth->user();$this->activity->log((int)$u['id'],'private-message.report.resolved','private_message_report',$id,[],$r->ip());$this->session->put('private-messages.admin.message',$this->translator->translate('private-messages::admin.message.report_resolved'));}catch(RuntimeException $e){$this->session->put('private-messages.admin.error',$this->error($e->getMessage()));}return Response::redirect('/admin/private-messages',303);}
    private function guard():?Response{$u=$this->auth->user();if($u===null)return Response::redirect('/login');return $this->authorization->allows((int)$u['id'],'private-messages.moderate')?null:Response::html($this->translator->translate('private-messages::admin.error.forbidden'),403);}
    private function id(mixed $v):int{$id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new RuntimeException('Invalid identifier.');return(int)$id;}
    private function error(string$message):string{$keys=['Invalid identifier.'=>'invalid_identifier','Open report not found.'=>'report_not_found'];return isset($keys[$message])?$this->translator->translate('private-messages::admin.error.'.$keys[$message]):$message;}
}
