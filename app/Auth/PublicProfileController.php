<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Membership\MembershipManagerInterface;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Content\ContentFormat;
use NovaNuke\Core\Content\ContentProfile;
use NovaNuke\Core\Content\ContentRendererInterface;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Security\CsrfTokenManager;
use RuntimeException;
use Twig\Markup;

final class PublicProfileController
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly AvatarStorage $avatars,
        private readonly AuthManager $auth,
        private readonly ViewRenderer $views,
        private readonly ContentRendererInterface $contentRenderer,
        private readonly EventDispatcher $events,
        private readonly CsrfTokenManager $csrf,
        private readonly MembershipManagerInterface $memberships,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        return Response::html($this->views->render('auth/users-index.twig', [
            'result' => $this->profiles->directory((int) $page, $this->auth->user() !== null),
        ]));
    }

    public function show(Request $request): Response
    {
        $username = (string) $request->attribute('username');
        if (! preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) return Response::html($this->translate('account.error.profile_not_found','Profile not found.'), 404);
        $profile = $this->profiles->byUsername($username);
        if ($profile === null) return Response::html($this->translate('account.error.profile_not_found','Profile not found.'), 404);
        $viewer = $this->auth->user();
        if ($profile['profile_visibility'] === 'members' && $viewer === null) return Response::html($this->translate('account.error.profile_sign_in','Sign in to view this profile.'), 403);
        $profile['bio_html'] = new Markup($this->contentRenderer->render(
            (string) ($profile['bio'] ?? ''), ContentFormat::fromInput($profile['bio_format'] ?? null, ContentFormat::Markdown), ContentProfile::Profile,
        ), 'UTF-8');
        $statisticsEvent = new ProfileStatisticsBuilding((int) $profile['id']);
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::PROFILE_STATISTICS_BUILDING, $statisticsEvent);
        $actions = [];
        if ($viewer !== null && (int) $viewer['id'] !== (int) $profile['id']) {
            $event = new ProfileActionsBuilding((int) $profile['id'], (string) $profile['username'], (int) $viewer['id']);
            $this->events->dispatch(\NovaNuke\Core\Events\EventName::PROFILE_ACTIONS_BUILDING, $event);
            $actions = $event->actions();
        }
        return Response::html($this->views->render('auth/profile-public.twig', ['profile' => $profile, 'viewer' => $viewer, 'profile_actions' => $actions, 'profile_statistics' => $statisticsEvent->statistics(), 'csrf_token' => $this->csrf->token(), 'membership' => $this->memberships->status((int) $profile['id'])]));
    }

    public function avatar(Request $request): Response
    {
        try { $avatar = $this->avatars->resolve((string) $request->attribute('filename')); }
        catch (RuntimeException) { return Response::html($this->translate('account.error.avatar_not_found','Avatar not found.'), 404); }
        return new Response(static function () use ($avatar): void { readfile($avatar['path']); }, 200, [
            'Content-Type' => $avatar['mime'], 'Content-Length' => (string) $avatar['size'],
            'Content-Disposition' => 'inline', 'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function translate(string $key,string $fallback): string
    {
        return $this->translator?->translate($key)??$fallback;
    }
}
