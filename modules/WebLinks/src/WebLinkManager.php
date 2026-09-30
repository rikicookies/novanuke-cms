<?php

declare(strict_types=1);

namespace Modules\WebLinks\src;

use NovaNuke\Auth\AuthManager;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Security\DatabaseRateLimiter;use RuntimeException;

final class WebLinkManager
{
    public function __construct(private readonly WebLinkRepository $links,private readonly AuthManager $auth,private readonly DatabaseRateLimiter $submissionLimiter,private readonly DatabaseRateLimiter $reportLimiter,private readonly string $appKey){}
    public function submit(array $data):int{$user=$this->auth->user();if($user===null)throw new RuntimeException('Sign in to submit a link.');$key='user:'.$user['id'];if($this->submissionLimiter->tooManyAttempts($key))throw new RuntimeException('Too many submissions. Please wait before trying again.');$id=$this->links->save(null,$data,(int)$user['id']);$this->submissionLimiter->hit($key);return$id;}
    public function visit(string $slug,Request $request):string{$link=$this->links->publicBySlug($slug);if($link===null)throw new RuntimeException('Link not found.');$user=$this->auth->user();if(!$this->links->canView($link,$user?(int)$user['id']:null))throw new RuntimeException('Link not found.');$identity=$user?'user:'.$user['id']:'guest:'.$request->ip().'|'.$request->userAgent();$this->links->recordVisit((int)$link['id'],$this->hash($identity));return(string)$link['url'];}
    public function report(int $id,Request $request):void{$link=$this->links->find($id);if($link===null||$link['status']!=='published')throw new RuntimeException('Link not found.');$user=$this->auth->user();if(!$this->links->canView($link,$user?(int)$user['id']:null))throw new RuntimeException('Link not found.');$reason=trim(strip_tags((string)$request->input('reason')));if(mb_strlen($reason)<5||mb_strlen($reason)>500)throw new RuntimeException('Report reason must contain 5-500 characters.');$identity=$user?'user:'.$user['id']:'guest:'.$request->ip();if($this->reportLimiter->tooManyAttempts($identity))throw new RuntimeException('Too many reports. Please wait before trying again.');$this->links->report($id,$user?(int)$user['id']:null,$this->hash($identity),$reason);$this->reportLimiter->hit($identity);}
    private function hash(string $value):string{if($this->appKey==='')throw new RuntimeException('APP_KEY is required for Web Links protection.');return hash_hmac('sha256',$value,$this->appKey);}
}
