<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Membership\MembershipManagerInterface;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\RateLimiter;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class AccountController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ProfileRepository $profiles,
        private readonly ProfileInput $input,
        private readonly AvatarUploadValidator $avatarValidator,
        private readonly AvatarStorage $avatars,
        private readonly AccountPasswordService $passwords,
        private readonly RateLimiter $passwordThrottle,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly MembershipManagerInterface $memberships,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function edit(): Response
    {
        $user = $this->auth->user(); if ($user === null) return Response::redirect('/login');
        $profile = $this->profiles->byUserId((int) $user['id']);
        if ($profile === null) return Response::html($this->translate('account.error.profile_not_found', 'Profile not found.'), 404);
        return $this->view($profile, [], null, $this->session->pull('account.message'));
    }

    public function update(Request $request): Response
    {
        $user = $this->auth->user(); if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $result = $this->input->validate($request->allInput());
        $current = $this->profiles->byUserId((int) $user['id']) ?? [];
        if ($result['errors'] !== []) return $this->view(array_replace($current, $result['data']), array_map(fn(string $error):string=>$this->error($error),$result['errors']), null, null, 422);
        $result['data']['preferences'] = array_replace((array) ($current['preferences'] ?? []), $result['data']['preferences']);
        $this->profiles->update((int) $user['id'], $result['data']);
        $this->activity->log((int) $user['id'], 'profile.updated', 'user', $user['id'], [], $request->ip());
        $this->session->put('account.message', $this->translate('account.message.profile_saved', 'Profile preferences saved.'));
        return Response::redirect('/account/profile', 303);
    }

    public function avatar(Request $request): Response
    {
        $user = $this->auth->user(); if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $profile = $this->profiles->byUserId((int) $user['id']); if ($profile === null) return Response::html($this->translate('account.error.profile_not_found', 'Profile not found.'), 404);
        try {
            $validated = $this->avatarValidator->validate($request->file('avatar'));
            $path = $this->avatars->store($validated);
            try { $this->profiles->setAvatar((int) $user['id'], $path); }
            catch (\Throwable $error) { $this->avatars->remove($path); throw $error; }
            try { $this->avatars->remove($profile['avatar_path'] ?: null); }
            catch (RuntimeException $error) { error_log('Previous avatar cleanup failed: ' . $error->getMessage()); }
            $this->activity->log((int) $user['id'], 'profile.avatar.updated', 'user', $user['id'], [], $request->ip());
            $this->session->put('account.message', $this->translate('account.message.avatar_updated', 'Avatar updated.'));
            return Response::redirect('/account/profile', 303);
        } catch (RuntimeException $error) {
            return $this->view($profile, [], $this->error($error->getMessage()), null, 422);
        }
    }

    public function removeAvatar(Request $request): Response
    {
        $user = $this->auth->user(); if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $profile = $this->profiles->byUserId((int) $user['id']); if ($profile === null) return Response::html($this->translate('account.error.profile_not_found', 'Profile not found.'), 404);
        $this->profiles->setAvatar((int) $user['id'], null);
        try { $this->avatars->remove($profile['avatar_path'] ?: null); }
        catch (RuntimeException $error) { error_log('Avatar cleanup failed: ' . $error->getMessage()); }
        $this->activity->log((int) $user['id'], 'profile.avatar.removed', 'user', $user['id'], [], $request->ip());
        $this->session->put('account.message', $this->translate('account.message.avatar_removed', 'Avatar removed.'));
        return Response::redirect('/account/profile', 303);
    }

    public function password(Request $request): Response
    {
        $user = $this->auth->user(); if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $key = (string) $user['id'];
        if ($this->passwordThrottle->tooManyAttempts($key)) {
            return $this->view($this->profiles->byUserId((int) $user['id']) ?? [], [], null, null, 429, $this->translate('account.error.password_throttled', 'Try changing the password again later.'))
                ->withHeader('Retry-After', (string) max(1, $this->passwordThrottle->retryAfter($key)));
        }
        $error = $this->passwords->change((int) $user['id'], $request->input('current_password'), $request->input('password'), $request->input('password_confirmation'));
        if ($error !== null) {
            $this->passwordThrottle->hit($key);
            return $this->view($this->profiles->byUserId((int) $user['id']) ?? [], [], null, null, 422, $this->error($error));
        }
        $this->passwordThrottle->clear($key);
        $this->activity->log((int) $user['id'], 'account.password.changed', 'user', $user['id'], [], $request->ip());
        $this->auth->logout(); $this->csrf->rotate();
        return Response::redirect('/login?password_changed=1', 303);
    }

    /** @param array<string,mixed> $profile @param array<string,string> $errors */
    private function view(array $profile, array $errors, ?string $avatarError, mixed $message, int $status = 200, ?string $passwordError = null): Response
    {
        $user = $this->auth->user() ?? [];
        $username = (string) ($profile['username'] ?? $user['username'] ?? '');
        $profile = array_replace([
            'username' => $username,
            'display_name' => $username,
            'avatar_path' => null,
            'bio' => null,
            'bio_format' => 'markdown',
            'website' => null,
            'location' => null,
            'locale' => 'en',
            'timezone' => 'UTC',
            'preferences' => [],
            'profile_visibility' => 'public',
        ], $profile);
        return Response::html($this->views->render('auth/profile-edit.twig', [
            'profile' => $profile, 'errors' => $errors, 'avatar_error' => $avatarError,
            'message' => is_string($message) ? $message : null, 'password_error' => $passwordError,
            'csrf_token' => $this->csrf->token(),
            'timezones' => timezone_identifiers_list(),
            'membership' => $user === [] ? null : $this->memberships->status((int) $user['id']),
            'scheduled_membership' => $user === [] ? null : $this->memberships->nextScheduled((int) $user['id']),
        ]), $status);
    }

    private function error(string $message): string
    {
        $keys=['Display name must contain between 2 and 100 characters.'=>'display_name','Biography cannot exceed 2,000 characters.'=>'bio','Select a valid biography format.'=>'bio_format','Enter a valid HTTP or HTTPS website URL.'=>'website','Location cannot exceed 120 characters.'=>'location','Select an available language.'=>'locale','Select a valid timezone.'=>'timezone','Select a valid profile visibility.'=>'visibility','Select a JPEG, PNG or WebP image.'=>'avatar_file','Avatar must be a non-empty image no larger than 2 MB.'=>'avatar_size','Avatar content or dimensions are invalid. Use 32–2048 pixel JPEG, PNG or WebP images.'=>'avatar_content','Enter your current password.'=>'current_password','The current password is incorrect.'=>'password_incorrect','Choose a password different from the current password.'=>'password_same','Use a password between 12 and 255 characters.'=>'password','The passwords do not match.'=>'password_match'];
        $key=$keys[$message]??null;
        return $key===null?$message:$this->translate('account.error.'.$key,$message);
    }

    private function translate(string $key,string $fallback): string
    {
        return $this->translator?->translate($key)??$fallback;
    }
}
