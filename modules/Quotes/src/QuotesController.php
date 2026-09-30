<?php

declare(strict_types=1);

namespace Modules\Quotes\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class QuotesController
{
    public function __construct(
        private readonly QuoteRepository $quotes,
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
        return Response::html($this->views->render('@quotes/index.twig', ['quotes' => $this->quotes->published()]));
    }

    public function admin(): Response
    {
        if ($guard = $this->guard()) return $guard;
        return Response::html($this->views->render('@quotes/admin/index.twig', [
            'quotes' => $this->quotes->all(),
            'csrf_token' => $this->csrf->token(),
            'message' => $this->session->pull('quotes.message'),
            'error' => $this->session->pull('quotes.error'),
        ]));
    }

    public function create(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('quotes::error.csrf'), 419);

        try {
            $quote = trim((string) $request->input('quote_text'));
            $attribution = trim((string) $request->input('attribution'));
            if ($quote === '' || mb_strlen($quote) > 500) throw new RuntimeException('Quote must contain 1 to 500 characters.');
            if (mb_strlen($attribution) > 120) throw new RuntimeException('Attribution cannot exceed 120 characters.');
            $id = $this->quotes->create($quote, $attribution, $request->input('is_published') === '1');
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'quotes.created', 'quote', $id, [], $request->ip());
            $this->session->put('quotes.message', $this->translator->translate('quotes::message.created'));
        } catch (RuntimeException $error) {
            $this->session->put('quotes.error', $this->error($error->getMessage()));
        }
        return Response::redirect('/admin/quotes', 303);
    }

    public function delete(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('quotes::error.csrf'), 419);
        $id = filter_var($request->attribute('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) return Response::html($this->translator->translate('quotes::error.invalid'), 422);
        $this->quotes->delete((int) $id);
        $actor = $this->auth->user();
        $this->activity->log((int) $actor['id'], 'quotes.deleted', 'quote', (int) $id, [], $request->ip());
        $this->session->put('quotes.message', $this->translator->translate('quotes::message.deleted'));
        return Response::redirect('/admin/quotes', 303);
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return $this->authorization->allows((int) $user['id'], 'quotes.manage') ? null : Response::html($this->translator->translate('quotes::error.forbidden'), 403);
    }

    private function error(string $message): string
    {
        $keys = [
            'Quote must contain 1 to 500 characters.' => 'quote_length',
            'Attribution cannot exceed 120 characters.' => 'attribution_length',
        ];
        return isset($keys[$message]) ? $this->translator->translate('quotes::error.' . $keys[$message]) : $message;
    }
}
