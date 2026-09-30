<?php

declare(strict_types=1);

namespace Modules\Pages\src;

use NovaNuke\Core\Media\MediaLibraryInterface;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Http\SafeReturnPath;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class AdminPagesController
{
    public function __construct(
        private readonly PageRepository $pages, private readonly PageInput $input,
        private readonly AuthManager $auth, private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity, private readonly EventDispatcher $events,
        private readonly CsrfTokenManager $csrf, private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly ?MediaLibraryInterface $media = null,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        if ($guard = $this->guard('pages.edit')) return $guard;
        return Response::html($this->views->render('@admin-pages/index.twig', [
            'pages' => $this->pages->adminPages(), 'csrf_token' => $this->csrf->token(),
            'message' => $this->session->pull('pages.message'),
        ]));
    }

    public function create(): Response
    {
        if ($guard = $this->guard('pages.edit')) return $guard;
        return $this->editor(null);
    }

    public function edit(Request $request): Response
    {
        if ($guard = $this->guard('pages.edit')) return $guard;
        try {
            $page = $this->pages->page($this->routeId($request));
            return $page === null ? Response::html($this->translate('error.not_found', 'Page not found.'), 404) : $this->editor($page);
        } catch (RuntimeException) { return Response::html($this->translate('error.not_found', 'Page not found.'), 404); }
    }

    public function save(Request $request): Response
    {
        if ($guard = $this->guard('pages.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        try {
            $actor = $this->auth->user();
            $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $data = $this->input->page($request->allInput(), $this->authorization->allows((int) $actor['id'], 'pages.publish'));
            $pageId = $this->pages->save($id ? (int) $id : null, $data, (int) $actor['id']);
            $action = $id ? \NovaNuke\Core\Events\EventName::CONTENT_UPDATED : \NovaNuke\Core\Events\EventName::CONTENT_CREATED;
            $this->events->dispatch($action, new PageChanged('pages', $pageId, (int) $actor['id']));
            $this->activity->log((int) $actor['id'], 'page.' . ($id ? 'updated' : 'created'), 'page', $pageId, ['status' => $data['status']], $request->ip());
            $this->session->put('pages.message', $this->translate('admin.message.saved', 'Page saved.'));
            return Response::redirect('/admin/pages', 303);
        } catch (RuntimeException $error) { return $this->editor($request->allInput(), $this->error($error->getMessage()), 422); }
    }

    public function delete(Request $request): Response
    {
        if ($guard = $this->guard('pages.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        if ($request->input('confirm_delete') !== '1') return Response::html($this->translate('admin.error.confirm_delete', 'Confirm deletion.'), 422);
        try {
            $id = $this->routeId($request); $this->pages->delete($id);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'page.deleted', 'page', $id, [], $request->ip());
            $this->session->put('pages.message', $this->translate('admin.message.deleted', 'Page moved to deleted state.'));
            return Response::redirect(SafeReturnPath::choose($request->input('return_to'), ['/pages'], '/admin/pages'), 303);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
    }

    private function editor(?array $page, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('@admin-pages/edit.twig', [
            'page' => $page ?? [], 'parents' => $this->pages->parentOptions(isset($page['id']) ? (int) $page['id'] : null),
            'roles' => $this->pages->roles(), 'can_publish' => $this->authorization->allows((int) $this->auth->user()['id'], 'pages.publish'),
            'csrf_token' => $this->csrf->token(), 'error' => $error,
            'media_available' => $this->media !== null, 'media_images' => $this->media?->all() ?? [],
        ]), $status);
    }

    private function guard(string $permission): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return $this->authorization->allows((int) $user['id'], $permission) ? null : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
    }

    private function routeId(Request $request): int
    {
        $id = filter_var($request->attribute('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid page identifier.');
        return (int) $id;
    }

    private function error(string $message): string
    {
        $keys = [
            'Page not found.' => 'not_found', 'Invalid page identifier.' => 'invalid_identifier',
            'Title is required and must not exceed 200 characters.' => 'title', 'Slug must use lowercase words separated by hyphens.' => 'slug',
            'Invalid publication status.' => 'status', 'You may save drafts but do not have permission to publish.' => 'publish_permission',
            'Scheduled pages require a future publication date.' => 'schedule_future', 'Use scheduled status for a future publication date.' => 'future_status',
            'Page content is required.' => 'content_required', 'Page content must not exceed 1,000,000 characters.' => 'content_length',
            'Invalid page template.' => 'template', 'Invalid page access type.' => 'access',
            'Select at least one role for role-restricted pages.' => 'roles_required', 'Invalid parent page.' => 'parent_invalid',
            'Page image must be a safe path below /uploads/.' => 'image', 'Invalid publication date.' => 'date',
            'The page slug is already in use.' => 'slug_used', 'A page cannot be its own parent.' => 'self_parent',
            'Page hierarchy cannot contain a cycle.' => 'hierarchy_cycle', 'Selected parent page does not exist.' => 'parent_missing',
            'One or more selected roles are invalid.' => 'roles_invalid',
        ];
        return isset($keys[$message]) ? $this->translate('admin.error.' . $keys[$message], $message) : $message;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate('pages::' . $key) ?? $fallback;
    }
}
