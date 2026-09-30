<?php

declare(strict_types=1);

namespace Modules\Polls\src;

use NovaNuke\Core\Http\Request;use NovaNuke\Core\Http\Response;use NovaNuke\Core\I18n\Translator;use NovaNuke\Core\Security\CsrfTokenManager;use NovaNuke\Core\Security\SessionManager;use NovaNuke\Core\View\ViewRenderer;use RuntimeException;

final class PublicPollsController
{
    public function __construct(private readonly PollRepository $polls,private readonly PollService $service,private readonly CsrfTokenManager $csrf,private readonly SessionManager $session,private readonly ViewRenderer $views,private readonly Translator $translator){}
    public function index():Response{return Response::html($this->views->render('@polls/index.twig',['polls'=>array_values(array_filter($this->polls->all(),static fn(array $p):bool=>$p['status']!=='draft'))]));}
    public function show(Request $request):Response{try{$id=$this->id($request->attribute('id'));$poll=$this->polls->publicPoll($id);if($poll===null)return Response::html($this->translator->translate('polls::error.not_found'),404);$key=$this->service->key($request);return Response::html($this->views->render('@polls/show.twig',['poll'=>$poll,'has_voted'=>$this->polls->hasVoted($id,$key),'csrf_token'=>$this->csrf->token(),'message'=>$this->session->pull('polls.message'),'error'=>$this->session->pull('polls.error')]));}catch(RuntimeException){return Response::html($this->translator->translate('polls::error.not_found'),404);}}
    public function vote(Request $request):Response
    {
        if(!$this->csrf->validate($request->input('_token')))return Response::html($this->translator->translate('polls::error.csrf'),419);try{$id=$this->id($request->attribute('id'));$this->service->vote($request,$id);$this->session->put('polls.message',$this->translator->translate('polls::message.voted'));}catch(RuntimeException $e){$this->session->put('polls.error',$this->error($e->getMessage()));$id=filter_var($request->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;}
        $return=(string)$request->input('_return','');if(!preg_match('#^/(?!/)[^\x00-\x20\x7F]*$#',$return))$return='/polls/'.$id;return Response::redirect($return,303);
    }
    private function id(mixed $value):int{$id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new RuntimeException('Invalid poll.');return(int)$id;}
    private function error(string $message):string{$keys=['Poll not found.'=>'not_found','Invalid poll.'=>'invalid','APP_KEY is required for poll voter protection.'=>'app_key','This poll is not accepting votes.'=>'not_accepting','Select the allowed number of options.'=>'select_options','One or more options are invalid.'=>'invalid_options','You have already voted in this poll.'=>'already_voted'];return isset($keys[$message])?$this->translator->translate('polls::error.'.$keys[$message]):$message;}
}
