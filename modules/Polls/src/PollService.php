<?php

declare(strict_types=1);

namespace Modules\Polls\src;

use NovaNuke\Auth\AuthManager;use NovaNuke\Core\Config\ConfigRepository;use NovaNuke\Core\Http\Request;use NovaNuke\Core\Security\SessionManager;use RuntimeException;

final class PollService
{
    private const GUEST_TOKEN='_poll_guest_token';
    public function __construct(private readonly PollRepository $polls,private readonly AuthManager $auth,private readonly SessionManager $session,private readonly ConfigRepository $config){}
    public function key(Request $request):string
    {
        $appKey=(string)$this->config->get('app.key','');if($appKey==='')throw new RuntimeException('APP_KEY is required for poll voter protection.');$user=$this->auth->user();
        if($user!==null)return hash_hmac('sha256','user:'.$user['id'],$appKey);
        $token=$this->session->get(self::GUEST_TOKEN);if(!is_string($token)||strlen($token)!==64){$token=bin2hex(random_bytes(32));$this->session->put(self::GUEST_TOKEN,$token);}
        return hash_hmac('sha256','guest:'.$token.'|'.$request->ip(),$appKey);
    }
    public function vote(Request $request,int $poll):void
    {
        $choices=$request->input('options',[]);if(!is_array($choices))$choices=[$choices];$user=$this->auth->user();$this->polls->vote($poll,$choices,$user?(int)$user['id']:null,$this->key($request));
    }
}
