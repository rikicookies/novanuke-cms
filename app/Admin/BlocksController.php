<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Blocks\BlockManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class BlocksController
{
    public function __construct(
        private readonly BlockManager $blocks,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        $guard = $this->guard();
        if ($guard !== null) {
            return $guard;
        }
        $message = $this->session->pull('blocks.message');

        return $this->view(is_string($message) ? $message : null);
    }

    public function save(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) {
            return $guard;
        }
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }
        try {
            $actor = $this->auth->user();
            $id = $this->blocks->save($request->allInput(), (int) $actor['id']);
            $this->activity->log((int) $actor['id'], 'block.saved', 'block', $id, [], $request->ip());
            $this->session->put('blocks.message', $this->translate('admin.blocks.message.saved', 'Block saved successfully.'));
            return Response::redirect('/admin/blocks', 303);
        } catch (RuntimeException $error) {
            return $this->view(null, $this->error($error->getMessage()), 422);
        }
    }

    public function delete(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) {
            return $guard;
        }
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }
        $id = filter_var($request->attribute('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return Response::html($this->translate('admin.blocks.error.invalid', 'Invalid block.'), 404);
        }
        if ($request->input('confirm_delete') !== '1') {
            return $this->view(null, $this->translate('admin.blocks.error.confirm_delete', 'Confirm block deletion before continuing.'), 422);
        }
        try {
            $this->blocks->delete((int) $id);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'block.deleted', 'block', $id, [], $request->ip());
            $this->session->put('blocks.message', $this->translate('admin.blocks.message.deleted', 'Block deleted.'));
            return Response::redirect('/admin/blocks', 303);
        } catch (RuntimeException $error) {
            return $this->view(null, $this->error($error->getMessage()), 422);
        }
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        return $this->authorization->allows((int) $user['id'], 'blocks.manage')
            ? null : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
    }

    private function view(?string $message = null, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('admin/blocks/index.twig', [
            'block_list' => $this->blocks->all(),
            'positions' => $this->blocks->positions(),
            'roles' => $this->blocks->roles(),
            'csrf_token' => $this->csrf->token(),
            'message' => $message,
            'error' => $error,
        ]), $status);
    }

    private function error(string $message): string
    {
        $keys = [
            'Block not found.' => 'not_found', 'The block slug is already in use.' => 'slug_used',
            'Block title is required and must not exceed 150 characters.' => 'title',
            'Block slug must use lowercase letters, numbers and hyphens.' => 'slug',
            'Invalid block position.' => 'position', 'Invalid page visibility mode.' => 'visibility',
            'Invalid block audience.' => 'audience', 'The end date must be later than the start date.' => 'date_order',
            'One or more selected roles are invalid.' => 'roles', 'Invalid block content format.' => 'content_format',
            'Block content must not exceed 100,000 characters.' => 'content_length',
            'Invalid start date.' => 'start_date', 'Invalid end date.' => 'end_date',
            'Invalid page pattern.' => 'page_pattern', 'Invalid module slug.' => 'module_slug',
        ];
        return isset($keys[$message]) ? $this->translate('admin.blocks.error.' . $keys[$message], $message) : $message;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate($key) ?? $fallback;
    }
}
