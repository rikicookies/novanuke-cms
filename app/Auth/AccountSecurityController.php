<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\RateLimiter;
use NovaNuke\Core\View\ViewRenderer;

final class AccountSecurityController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AccountSecurityRepository $security,
        private readonly AccountLifecycleService $lifecycle,
        private readonly AccountDeletionInput $input,
        private readonly AvatarStorage $avatars,
        private readonly ProfileRepository $profiles,
        private readonly RateLimiter $limiter,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function show(): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return $this->view($user);
    }

    public function delete(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $errors = array_map(fn(string $error):string=>$this->error($error),$this->input->validate((string) $user['username'], $request->input('confirmation'), $request->input('password')));
        $key = (string) $user['id'];
        if ($this->limiter->tooManyAttempts($key)) {
            $errors['password'] = $this->translate('account.error.delete_throttled', 'Too many attempts. Try again later.');
            return $this->view($user, $errors, 429)
                ->withHeader('Retry-After', (string) max(1, $this->limiter->retryAfter($key)));
        }
        if ($errors !== []) return $this->view($user, $errors, 422);
        $profile = $this->profiles->byUserId((int) $user['id']);
        $error = $this->lifecycle->anonymize((int) $user['id'], (string) $request->input('password'));
        if ($error !== null) {
            $this->limiter->hit($key);
            return $this->view($user, ['password' => $this->error($error)], 422);
        }
        $this->limiter->clear($key);
        try { $this->avatars->remove(is_array($profile) ? ($profile['avatar_path'] ?? null) : null); } catch (\RuntimeException) {}
        $this->auth->logout();
        $this->csrf->rotate();
        return Response::redirect('/account/deleted', 303);
    }

    public function deleted(): Response
    {
        return Response::html($this->views->render('auth/account-deleted.twig'));
    }

    /** @param array<string,mixed> $user @param array<string,string> $errors */
    private function view(array $user, array $errors = [], int $status = 200): Response
    {
        return Response::html($this->views->render('auth/account-security.twig', [
            'account_user' => $user,
            'logins' => $this->security->recentLogins((int) $user['id']),
            'errors' => $errors,
            'csrf_token' => $this->csrf->token(),
        ]), $status);
    }

    private function error(string $message): string
    {
        $keys=['Enter your username exactly to confirm account deletion.'=>'delete_confirmation','Enter your current password.'=>'current_password','The current password is incorrect.'=>'password_incorrect','The last active Super Administrator cannot delete their account.'=>'last_super_delete'];
        $key=$keys[$message]??null;
        return $key===null?$message:$this->translate('account.error.'.$key,$message);
    }

    private function translate(string $key,string $fallback): string
    {
        return $this->translator?->translate($key)??$fallback;
    }
}
