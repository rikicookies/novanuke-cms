<?php

declare(strict_types=1);

namespace Modules\Friends\src;

use NovaNuke\Auth\AuthManager;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\Security\CsrfTokenManager;use NovaNuke\Core\Security\SessionManager;use NovaNuke\Core\View\ViewRenderer;use NovaNuke\Core\I18n\Translator;use RuntimeException;

final class PublicFriendsController
{
    public function __construct(private readonly FriendRepository $repository,private readonly FriendService $service,private readonly AuthManager $auth,private readonly CsrfTokenManager $csrf,private readonly SessionManager $session,private readonly ViewRenderer $views,private readonly Translator $translator) {}
    public function index():Response{if(!$user=$this->auth->user())return Response::redirect('/login');return Response::html($this->views->render('@friends/index.twig',$this->repository->lists((int)$user['id'])+['csrf_token'=>$this->csrf->token(),'message'=>$this->session->pull('friends.message'),'error'=>$this->session->pull('friends.error')]));}
    public function request(Request $r):Response{return$this->action($r,fn(int$u,int$o)=>$this->service->request($u,$o),'friends::message.requested');}
    public function accept(Request $r):Response{return$this->action($r,fn(int$u,int$o)=>$this->service->accept($u,$o),'friends::message.accepted');}
    public function decline(Request $r):Response{return$this->action($r,fn(int$u,int$o)=>$this->service->decline($u,$o),'friends::message.declined');}
    public function remove(Request $r):Response{return$this->action($r,fn(int$u,int$o)=>$this->service->remove($u,$o),'friends::message.removed');}
    public function block(Request $r):Response{return$this->action($r,fn(int$u,int$o)=>$this->service->block($u,$o),'friends::message.blocked');}
    public function unblock(Request $r):Response{return$this->action($r,fn(int$u,int$o)=>$this->repository->unblock($u,$o),'friends::message.unblocked');}
    private function action(Request$r,callable$callback,string$message):Response{if(!$user=$this->auth->user())return Response::redirect('/login');if(!$this->csrf->validate($r->input('_token')))return Response::html($this->translator->translate('friends::error.csrf'),419);$other=filter_var($r->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($other===false)return Response::html($this->translator->translate('friends::error.invalid_user'),404);try{$callback((int)$user['id'],(int)$other);$this->session->put('friends.message',$this->translator->translate($message));}catch(RuntimeException$e){$this->session->put('friends.error',$this->error($e->getMessage()));}return Response::redirect('/friends',303);}
    private function error(string$message):string{$keys=['Friend requests are unavailable between these users.'=>'unavailable','You cannot perform this action on yourself.'=>'self_action','User not found.'=>'user_not_found','A friendship or request already exists.'=>'already_exists','Friendship not found.'=>'friendship_not_found','Pending request not found.'=>'pending_not_found'];return isset($keys[$message])?$this->translator->translate('friends::error.'.$keys[$message]):$message;}
}
