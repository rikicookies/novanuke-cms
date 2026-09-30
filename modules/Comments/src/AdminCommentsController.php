<?php

declare(strict_types=1);

namespace Modules\Comments\src;

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
use RuntimeException;

final class AdminCommentsController
{
    public function __construct(
        private readonly CommentRepository $repository, private readonly SettingsRepository $settings,
        private readonly AuthManager $auth, private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity, private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views, private readonly SessionManager $session,
        private readonly Translator $translator,
    ) {
    }

    public function index(): Response
    {
        if ($guard = $this->guard()) return $guard;
        return $this->view($this->session->pull('comments.admin.message'), $this->session->pull('comments.admin.error'));
    }

    public function moderate(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('comments::error.csrf'), 419);
        try {
            $id = $this->id($request->attribute('id'));
            $status = (string) $request->input('status');
            if (! in_array($status, ['pending', 'approved', 'spam', 'deleted'], true)) throw new RuntimeException('Invalid moderation status.');
            $this->repository->moderate($id, $status);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'comment.moderated', 'comment', $id, ['status' => $status], $request->ip());
            return $this->redirect($this->translator->translate('comments::admin.message.status_updated'));
        } catch (RuntimeException $error) { return $this->redirect(null, $this->error($error->getMessage())); }
    }

    public function resolve(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('comments::error.csrf'), 419);
        try {
            $id = $this->id($request->attribute('id'));
            $this->repository->resolveReport($id);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'comment.report.resolved', 'comment_report', $id, [], $request->ip());
            return $this->redirect($this->translator->translate('comments::admin.message.report_resolved'));
        } catch (RuntimeException $error) { return $this->redirect(null, $this->error($error->getMessage())); }
    }

    public function settings(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translator->translate('comments::error.csrf'), 419);
        $this->settings->setBoolean('comments.guests_allowed', $request->input('guests_allowed') === '1', 'comments');
        $this->settings->setBoolean('comments.moderation_required', $request->input('moderation_required') === '1', 'comments');
        $actor = $this->auth->user();
        $this->activity->log((int) $actor['id'], 'comments.settings.updated', 'settings', 'comments', [], $request->ip());
        return $this->redirect($this->translator->translate('comments::admin.message.settings_updated'));
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return $this->authorization->allows((int) $user['id'], 'comments.moderate') ? null : Response::html($this->translator->translate('comments::admin.error.forbidden'), 403);
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid identifier.');
        return (int) $id;
    }

    private function view(?string $message = null, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('@admin-comments/index.twig', [
            'queue' => $this->repository->moderationQueue(), 'reports' => $this->repository->openReports(),
            'guests_allowed' => $this->settings->boolean('comments.guests_allowed', false),
            'moderation_required' => $this->settings->boolean('comments.moderation_required', true),
            'csrf_token' => $this->csrf->token(), 'message' => $message, 'error' => $error,
        ]), $status);
    }

    private function redirect(?string $message = null, ?string $error = null): Response
    {
        if ($message !== null) $this->session->put('comments.admin.message', $message);
        if ($error !== null) $this->session->put('comments.admin.error', $error);
        return Response::redirect('/admin/comments', 303);
    }

    private function error(string $message): string
    {
        $keys = [
            'Invalid moderation status.' => 'invalid_status',
            'Invalid identifier.' => 'invalid_identifier',
            'Comment not found.' => 'comment_not_found',
            'Open report not found.' => 'report_not_found',
        ];
        return isset($keys[$message]) ? $this->translator->translate('comments::admin.error.' . $keys[$message]) : $message;
    }
}
