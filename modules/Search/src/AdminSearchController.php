<?php

declare(strict_types=1);

namespace Modules\Search\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\View\ViewRenderer;

final class AdminSearchController
{
    public function __construct(
        private readonly SearchRepository $searches,
        private readonly SettingsRepository $settings,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly Translator $translator,
    ) {
    }

    public function index(): Response
    {
        if ($guard = $this->guard()) return $guard;
        return Response::html($this->views->render('@admin-search/index.twig', [
            'logging_enabled' => $this->settings->boolean('search.log_terms', false),
            'popular' => $this->searches->popular(), 'csrf_token' => $this->csrf->token(),
            'message' => $this->session->pull('search.message'),
        ]));
    }

    public function update(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('search::admin.error.csrf'), 419);
        $enabled = $request->input('log_terms') === '1';
        $this->settings->setBoolean('search.log_terms', $enabled, 'search');
        $user = $this->auth->user();
        $this->activity->log((int) $user['id'], 'search.settings.updated', 'setting', null, ['log_terms' => $enabled], $request->ip());
        $this->session->put('search.message', $this->translator->translate('search::admin.message.saved'));
        return Response::redirect('/admin/search', 303);
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return $this->authorization->allows((int) $user['id'], 'search.manage') ? null : Response::html($this->translator->translate('search::admin.error.forbidden'), 403);
    }
}
