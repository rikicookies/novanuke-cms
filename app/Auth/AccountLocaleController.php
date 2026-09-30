<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\LocaleRegistry;
use NovaNuke\Core\Security\CsrfTokenManager;

final class AccountLocaleController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ProfileRepository $profiles,
        private readonly LocaleRegistry $locales,
        private readonly CsrfTokenManager $csrf,
    ) {}

    public function update(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html('Invalid or expired CSRF token.', 419);
        }

        $locale = trim((string) $request->input('locale', ''));
        if (! $this->locales->supports($locale)) {
            return Response::html('Unsupported language.', 422);
        }

        $this->profiles->setLocale((int) $user['id'], $locale);
        return Response::redirect('/admin', 303);
    }
}
