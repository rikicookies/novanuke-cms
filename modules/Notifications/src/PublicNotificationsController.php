<?php

declare(strict_types=1);

namespace Modules\Notifications\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class PublicNotificationsController
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly AuthManager $auth,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly Translator $translator,
    ) {
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return Response::html($this->views->render('@notifications/index.twig', [
            'notifications' => $this->repository->latest((int) $user['id']),
            'csrf_token' => $this->csrf->token(),
            'marked_count' => $this->session->pull('notifications.marked'),
        ]));
    }

    public function read(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('notifications::error.csrf'), 419);
        try {
            $id = filter_var($request->attribute('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new RuntimeException('Invalid notification identifier.');
            $this->repository->markRead((int) $id, (int) $user['id']);
        } catch (RuntimeException) {
            return Response::html($this->translator->translate('notifications::error.not_found'), 404);
        }
        return Response::redirect('/notifications', 303);
    }

    public function readAll(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('notifications::error.csrf'), 419);
        $count = $this->repository->markAllRead((int) $user['id']);
        $this->session->put('notifications.marked', $count);
        return Response::redirect('/notifications', 303);
    }
}
