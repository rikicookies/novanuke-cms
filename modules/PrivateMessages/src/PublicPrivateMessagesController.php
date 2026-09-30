<?php

declare(strict_types=1);

namespace Modules\PrivateMessages\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\I18n\Translator;
use RuntimeException;

final class PublicPrivateMessagesController
{
    public function __construct(private readonly PrivateMessageRepository $repository,private readonly PrivateMessageService $service,private readonly AuthManager $auth,private readonly CsrfTokenManager $csrf,private readonly SessionManager $session,private readonly ViewRenderer $views,private readonly Translator $translator) {}

    public function inbox(): Response { if(!$user=$this->user())return Response::redirect('/login');return $this->view('@private-messages/inbox.twig',['conversations'=>$this->repository->inbox((int)$user['id'])]); }
    public function sent(): Response { if(!$user=$this->user())return Response::redirect('/login');return $this->view('@private-messages/sent.twig',['messages'=>$this->service->renderMessages($this->repository->sent((int)$user['id']))]); }
    public function compose(Request $request): Response { if(!$this->user())return Response::redirect('/login');return $this->view('@private-messages/compose.twig',['recipient'=>(string)$request->query('to','')]); }
    public function show(Request $request): Response { if(!$user=$this->user())return Response::redirect('/login');try{$id=$this->id($request->attribute('id'));}catch(RuntimeException){return Response::html($this->translator->translate('private-messages::error.conversation_not_found'),404);}$thread=$this->repository->conversation($id,(int)$user['id']);if($thread===null)return Response::html($this->translator->translate('private-messages::error.conversation_not_found'),404);$thread['messages']=$this->service->renderMessages($thread['messages']);return $this->view('@private-messages/show.twig',['thread'=>$thread]); }

    public function store(Request $request): Response
    {
        if(!$user=$this->user())return Response::redirect('/login');if(!$this->csrf->validate($request->input('_token')))return Response::html($this->translator->translate('private-messages::error.csrf'),419);
        try{$conversation=$this->service->compose((int)$user['id'],(string)$request->input('recipient'),$request->input('subject'),$request->input('body'),$request->input('body_format'));$this->session->put('private-messages.message',$this->translator->translate('private-messages::message.sent'));return Response::redirect('/messages/'.$conversation,303);}
        catch(RuntimeException $e){return $this->view('@private-messages/compose.twig',['recipient'=>$request->input('recipient'),'subject'=>$request->input('subject'),'body'=>$request->input('body'),'body_format'=>$request->input('body_format'),'error'=>$this->error($e->getMessage())],422);}
    }

    public function reply(Request $request): Response { return $this->action($request,fn(int $user,int $id)=>$this->service->reply($id,$user,$request->input('body'),$request->input('body_format')),'private-messages::message.reply_sent'); }
    public function delete(Request $request): Response { return $this->action($request,fn(int $user,int $id)=>$this->repository->deleteFor($id,$user),'private-messages::message.removed','/messages'); }
    public function report(Request $request): Response { return $this->action($request,fn(int $user,int $id)=>$this->service->report($id,$user,$request->input('reason')),'private-messages::message.reported'); }
    public function block(Request $request): Response { return $this->action($request,fn(int $user,int $id)=>$this->repository->block($user,$id),'private-messages::message.blocked','/messages/blocks'); }
    public function unblock(Request $request): Response { return $this->action($request,fn(int $user,int $id)=>$this->repository->unblock($user,$id),'private-messages::message.unblocked','/messages/blocks'); }
    public function blocks(): Response { if(!$user=$this->user())return Response::redirect('/login');return $this->view('@private-messages/blocks.twig',['blocks'=>$this->repository->blocks((int)$user['id'])]); }

    private function action(Request $request,callable $callback,string $message,string $fallback=''): Response
    {
        if(!$user=$this->user())return Response::redirect('/login');if(!$this->csrf->validate($request->input('_token')))return Response::html($this->translator->translate('private-messages::error.csrf'),419);
        try{$id=$this->id($request->attribute('id'));$callback((int)$user['id'],$id);$this->session->put('private-messages.message',$this->translator->translate($message));}
        catch(RuntimeException $e){$this->session->put('private-messages.error',$this->error($e->getMessage()));return Response::redirect($fallback!==''?$fallback:'/messages',303);}
        $conversation=filter_var($request->input('conversation_id',$id),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:$id;
        return Response::redirect($fallback!==''?$fallback:'/messages/'.$conversation,303);
    }
    private function view(string $template,array $data=[],int $status=200): Response { return Response::html($this->views->render($template,$data+['csrf_token'=>$this->csrf->token(),'message'=>$this->session->pull('private-messages.message'),'error'=>$this->session->pull('private-messages.error')]),$status); }
    private function user(): ?array { return $this->auth->user(); }
    private function error(string$message):string{$keys=['Invalid identifier.'=>'invalid_identifier','Conversation not found.'=>'conversation_not_found','Recipient was not found.'=>'recipient_not_found','You cannot message yourself.'=>'self_message','Too many reports. Please wait before trying again.'=>'too_many_reports','Message must contain visible text.'=>'visible_text','Messaging is unavailable between these users.'=>'messaging_unavailable','Too many messages. Please wait before trying again.'=>'too_many_messages','You cannot block yourself.'=>'self_block','User was not found.'=>'user_not_found','Message cannot be reported.'=>'cannot_report','You already reported this message.'=>'already_reported','Enter a valid recipient username.'=>'recipient_invalid','Subject must contain 2-200 characters.'=>'subject_length','Message must contain 2-5000 characters.'=>'message_length','Report reason must contain 5-500 characters.'=>'report_reason'];return isset($keys[$message])?$this->translator->translate('private-messages::error.'.$keys[$message]):$message;}
    private function id(mixed $value): int { $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new RuntimeException('Invalid identifier.');return(int)$id; }
}
