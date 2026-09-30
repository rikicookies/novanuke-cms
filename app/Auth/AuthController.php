<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Security\RateLimiter;
use NovaNuke\Core\Security\AuthorizationService;

final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly RateLimiter $throttle,
        private readonly LoginValidator $validator,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        $currentUser = $this->auth->user();
        if ($currentUser !== null) {
            return Response::redirect(
                $this->authorization->allows((int) $currentUser['id'], 'admin.access') ? '/admin' : '/'
            );
        }

        return Response::html($this->views->render('auth/login.twig', [
            'csrf_token' => $this->csrf->token(),
            'errors' => [],
            'old_login' => '',
            'password_changed' => $request->query('password_changed') === '1',
        ]));
    }

    public function login(Request $request): Response
    {
        $input = $request->allInput();
        if (! $this->csrf->validate($input['_token'] ?? null)) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }

        $errors = array_map(fn (string $error): string => $this->error($error), $this->validator->validate($input));
        $login = trim((string) ($input['login'] ?? ''));
        $key = hash('sha256', strtolower($login) . '|' . $request->ip());

        if ($this->throttle->tooManyAttempts($key)) {
            $retryAfter = max(1, $this->throttle->retryAfter($key));
            return Response::html($this->views->render('auth/login.twig', [
                'csrf_token' => $this->csrf->token(),
                'errors' => ['login' => $this->translate('auth.error.login_throttled', 'Too many login attempts. Try again in {seconds} seconds.', ['seconds' => $retryAfter])],
                'old_login' => $login,
                'password_changed' => false,
            ]), 429)->withHeader('Retry-After', (string) $retryAfter);
        }

        if ($errors === []) {
            $user = $this->auth->attempt($login, (string) $input['password'], $request->ip(), $request->userAgent());
            if ($user !== null) {
                $this->throttle->clear($key);
                $this->csrf->rotate();
                if ((bool) ($user['must_change_password'] ?? false)) {
                    return Response::redirect('/account/profile?password_required=1');
                }
                return Response::redirect(
                    $this->authorization->allows((int) $user['id'], 'admin.access') ? '/admin' : '/'
                );
            }

            $this->throttle->hit($key);
            $errors['login'] = $this->translate('auth.error.credentials', 'The credentials are incorrect or the account is unavailable.');
        }

        return Response::html($this->views->render('auth/login.twig', [
            'csrf_token' => $this->csrf->token(),
            'errors' => $errors,
            'old_login' => $login,
            'password_changed' => false,
        ]), 422);
    }

    public function logout(Request $request): Response
    {
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }

        $this->auth->logout();
        return Response::redirect('/login');
    }

    private function error(string $message): string
    {
        return match ($message) {
            'Enter your username or email address.' => $this->translate('auth.error.identifier', $message),
            'Enter your password.' => $this->translate('auth.error.password_required', $message),
            default => $message,
        };
    }

    /** @param array<string,scalar|null> $parameters */
    private function translate(string $key, string $fallback, array $parameters = []): string
    {
        if ($this->translator !== null) return $this->translator->translate($key, $parameters);
        foreach ($parameters as $name => $value) $fallback = str_replace('{'.$name.'}', (string) $value, $fallback);
        return $fallback;
    }
}
