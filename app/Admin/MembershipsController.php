<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use InvalidArgumentException;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Membership\MembershipRepository;
use NovaNuke\Core\Membership\MembershipService;
use NovaNuke\Core\Membership\MembershipStatusPresenter;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\View\ViewRenderer;

final class MembershipsController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly MembershipRepository $memberships,
        private readonly MembershipService $service,
        private readonly MembershipStatusPresenter $presenter,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {}

    public function index(Request $request): Response
    {
        if ($guard=$this->guard()) return $guard;
        $filter=(string)$request->query('status','active');
        $search=trim((string)$request->query('q',''));
        $data=$this->memberships->overview($filter,$search);
        return Response::html($this->views->render('admin/memberships/index.twig',[
            'memberships'=>$data['items'],
            'membership_counts'=>$data['counts'],
            'membership_plans'=>$this->service->plans(),
            'selected_status'=>in_array($filter,['all','active','expiring','lifetime','scheduled','inactive','never'],true)?$filter:'active',
            'search'=>mb_substr($search,0,100),
            'csrf_token'=>$this->csrf->token(),
        ]));
    }


    public function show(Request $request): Response
    {
        if ($guard=$this->guard()) return $guard;
        $userId=filter_var($request->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($userId===false) return Response::html($this->translate('admin.memberships.error.user_not_found','User not found.'),404);
        $user=$this->memberships->user((int)$userId);
        if($user===null) return Response::html($this->translate('admin.memberships.error.user_not_found','User not found.'),404);

        return Response::html($this->views->render('admin/memberships/show.twig',[
            'membership_user'=>$user,
            'membership_status'=>$this->presenter->present($this->service->status((int)$userId)),
            'membership_history'=>$this->memberships->history((int)$userId),
            'membership_plans'=>$this->service->plans(),
            'scheduled_membership'=>$this->presenter->scheduled($this->service->nextScheduled((int)$userId)),
            'csrf_token'=>$this->csrf->token(),
        ]));
    }

    public function assign(Request $request): Response
    {
        if ($guard=$this->guard()) return $guard;
        if(!$this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf','Invalid or expired CSRF token.'),419);
        $userId=filter_var($request->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($userId===false || $this->memberships->user((int)$userId)===null) return Response::html($this->translate('admin.memberships.error.user_not_found','User not found.'),404);
        $plan=trim((string)$request->input('plan',''));
        $note=trim((string)$request->input('note',''));
        $actor=$this->auth->user();
        try {
            $status=$this->service->assign((int)$userId,$plan,(int)$actor['id'],$note===''?null:$note);
        } catch (InvalidArgumentException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),422);
        }
        $action=$plan==='free'?'membership.revoked':'membership.assigned';
        $this->activity->log((int)$actor['id'],$action,'user',(int)$userId,[
            'plan'=>$plan,'expires_at'=>$status['expires_at']??null,
        ],$request->ip());
        return Response::redirect('/admin/memberships/'.(int)$userId,303);
    }


    public function extend(Request $request): Response
    {
        if ($guard=$this->guard()) return $guard;
        if(!$this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf','Invalid or expired CSRF token.'),419);
        $userId=filter_var($request->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $days=filter_var($request->input('days'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3650]]);
        if($userId===false||$days===false||$this->memberships->user((int)$userId)===null) return Response::html($this->translate('admin.memberships.error.extension','Invalid membership extension.'),422);
        $actor=$this->auth->user();$note=trim((string)$request->input('note',''));
        try {
            $status=$this->service->extendDays((int)$userId,(int)$days,(int)$actor['id'],$note===''?null:$note);
        } catch (InvalidArgumentException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),422);
        }
        $this->activity->log((int)$actor['id'],'membership.extended','user',(int)$userId,['days'=>(int)$days,'expires_at'=>$status['expires_at']??null],$request->ip());
        return Response::redirect('/admin/memberships/'.(int)$userId,303);
    }

    public function schedule(Request $request): Response
    {
        if ($guard=$this->guard()) return $guard;
        if(!$this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf','Invalid or expired CSRF token.'),419);
        $userId=filter_var($request->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($userId===false||$this->memberships->user((int)$userId)===null) return Response::html($this->translate('admin.memberships.error.user_not_found','User not found.'),404);
        $plan=trim((string)$request->input('plan',''));$startsAt=trim((string)$request->input('starts_at',''));$note=trim((string)$request->input('note',''));$actor=$this->auth->user();
        try{$scheduled=$this->service->schedule((int)$userId,$plan,$startsAt,(int)$actor['id'],$note===''?null:$note);}
        catch(InvalidArgumentException $error){return Response::html(htmlspecialchars($this->error($error->getMessage()),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),422);}
        $this->activity->log((int)$actor['id'],'membership.scheduled','user',(int)$userId,['plan'=>$plan,'starts_at'=>$scheduled['starts_at']??$startsAt],$request->ip());
        return Response::redirect('/admin/memberships/'.(int)$userId,303);
    }

    public function cancelScheduled(Request $request): Response
    {
        if ($guard=$this->guard()) return $guard;
        if(!$this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf','Invalid or expired CSRF token.'),419);
        $userId=filter_var($request->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($userId===false||$this->memberships->user((int)$userId)===null) return Response::html($this->translate('admin.memberships.error.user_not_found','User not found.'),404);
        $actor=$this->auth->user();
        $cancelled=$this->service->cancelScheduled((int)$userId,(int)$actor['id']);
        if($cancelled){
            $this->activity->log((int)$actor['id'],'membership.schedule_cancelled','user',(int)$userId,[],$request->ip());
        }
        return Response::redirect('/admin/memberships/'.(int)$userId,303);
    }

    public function revoke(Request $request): Response
    {
        if ($guard=$this->guard()) return $guard;
        if(!$this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf','Invalid or expired CSRF token.'),419);
        $userId=filter_var($request->attribute('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($userId===false || $this->memberships->user((int)$userId)===null) return Response::html($this->translate('admin.memberships.error.user_not_found','User not found.'),404);
        $actor=$this->auth->user();
        $this->service->revoke((int)$userId,(int)$actor['id']);
        $this->activity->log((int)$actor['id'],'membership.revoked','user',(int)$userId,[],$request->ip());
        return Response::redirect('/admin/memberships/'.(int)$userId,303);
    }

    private function guard(): ?Response
    {
        $user=$this->auth->user();
        if($user===null) return Response::redirect('/login');
        return $this->authorization->allows((int)$user['id'],'memberships.manage')?null:Response::html($this->translate('admin.error.forbidden','Forbidden'),403);
    }

    private function error(string $message): string
    {
        $keys=[
            'Unknown membership plan.'=>'plan',
            'Membership duration must be between 1 and 3650 days.'=>'duration',
            'Membership extension must be between 1 and 3650 days.'=>'extension_days',
            'Membership note must not exceed 255 characters.'=>'note',
            'User does not exist.'=>'user_not_found',
            'Only an active VIP membership can be extended.'=>'active_required',
            'Lifetime VIP does not need an expiration extension.'=>'lifetime_extension',
            'Membership start date is invalid.'=>'start_date',
            'Scheduled membership must start in the future.'=>'start_future',
            'Lifetime VIP cannot have a future overlapping membership.'=>'lifetime_overlap',
            'Scheduled membership cannot overlap the active VIP period.'=>'active_overlap',
            'A future membership is already scheduled for this user.'=>'already_scheduled',
        ];
        $key=$keys[$message]??null;
        return $key===null?$message:$this->translate('admin.memberships.error.'.$key,$message);
    }

    private function translate(string $key,string $fallback): string
    {
        return $this->translator?->translate($key)??$fallback;
    }
}
