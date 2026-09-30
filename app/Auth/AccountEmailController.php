<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\RateLimiter;
use NovaNuke\Core\View\ViewRenderer;

final class AccountEmailController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AccountEmailService $emails,
        private readonly AccountEmailInput $input,
        private readonly RateLimiter $limiter,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function show(): Response
    {
        $user = $this->auth->user();
        return $user === null ? Response::redirect('/login') : $this->view((string) $user['email']);
    }

    public function request(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $data = $this->input->validate($request->input('email'), $request->input('password'));
        $data['errors'] = array_map(fn(string $error):string=>$this->error($error),$data['errors']);
        $key = (string) $user['id'];
        if ($this->limiter->tooManyAttempts($key)) {
            $data['errors']['email'] = $this->translate('auth.error.requests_throttled', 'Too many requests. Try again later.');
            return $this->view((string) $user['email'], $data['email'], $data['errors'], 429)
                ->withHeader('Retry-After', (string) max(1, $this->limiter->retryAfter($key)));
        }
        if ($data['errors'] !== []) return $this->view((string) $user['email'], $data['email'], $data['errors'], 422);
        $error = $this->emails->request((int) $user['id'], $data['email'], $data['password']);
        if ($error !== null) {
            $this->limiter->hit($key);
            return $this->view((string) $user['email'], $data['email'], ['email' => $this->error($error)], 422);
        }
        $this->limiter->hit($key);
        $this->activity->log((int) $user['id'], 'account.email_change.requested', 'user', $user['id'], [], $request->ip());
        return $this->view((string) $user['email'], '', [], 200, true);
    }

    public function verify(Request $request): Response
    {
        $userId = $this->emails->confirm((string) $request->attribute('token'));
        $verified = $userId !== null;
        if ($verified) {
            $this->activity->log($userId, 'account.email_changed', 'user', $userId, [], $request->ip());
            if ($this->auth->user() !== null) $this->auth->logout();
        }
        return Response::html($this->views->render('auth/email-change-result.twig', ['verified' => $verified]), $verified ? 200 : 422);
    }

    /** @param array<string,string> $errors */
    private function view(string $currentEmail, string $email = '', array $errors = [], int $status = 200, bool $sent = false): Response
    {
        return Response::html($this->views->render('auth/email-change.twig', [
            'current_email' => $currentEmail, 'email' => $email, 'errors' => $errors,
            'sent' => $sent, 'csrf_token' => $this->csrf->token(),
        ]), $status);
    }

    private function error(string $message): string
    {
        $keys=['Enter a valid email address.'=>'email','Enter your current password.'=>'current_password','The current password is incorrect.'=>'password_incorrect','Enter an email different from your current address.'=>'email_same','That email address is already registered.'=>'email_registered'];
        $key=$keys[$message]??null;
        return $key===null?$message:$this->translate('account.error.'.$key,$message);
    }

    private function translate(string $key,string $fallback): string
    {
        return $this->translator?->translate($key)??$fallback;
    }
}
