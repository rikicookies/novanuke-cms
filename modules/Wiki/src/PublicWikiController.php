<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use NovaNuke\Core\Comments\CommentProviderInterface;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;
use Twig\Markup;

final class PublicWikiController
{
    public function __construct(
        private readonly WikiRepository $pages,
        private readonly WikiInput $input,
        private readonly WikiNavigation $navigation,
        private readonly WikiLinkPresenter $linkPresenter,
        private readonly WikiAttachmentManager $attachments,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly WikiMarkdownRenderer $markdownRenderer,
        private readonly ViewRenderer $views,
        private readonly SessionManager $session,
        private readonly CsrfTokenManager $csrf,
        private readonly ?CommentProviderInterface $comments = null,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            $namespace = $this->input->namespace($request->query('namespace', ''));
        } catch (RuntimeException) {
            return Response::html($this->translate('error.namespace_not_found', 'Wiki namespace not found.'), 404);
        }
        $user = $this->auth->user();
        if ($namespace !== '') {
            $start = $this->pages->publishedByPath($namespace . ':start');
            if ($start !== null && $this->pages->canView($start, $user ? (int) $user['id'] : null)) {
                $canEdit = $user !== null && $this->authorization->allows((int) $user['id'], 'wiki.edit');
                return $this->renderPage($start, $user, $canEdit);
            }
        }
        $directory = $this->navigation->directory(
            $this->pages->directory($user ? (int) $user['id'] : null),
            $namespace,
        );
        if ($namespace !== '' && $directory['pages'] === [] && $directory['namespaces'] === []) {
            return Response::html($this->translate('error.namespace_not_found', 'Wiki namespace not found.'), 404);
        }
        return Response::html($this->views->render('@wiki/index.twig', [
            'namespace' => $namespace,
            'pages' => $directory['pages'],
            'namespaces' => $directory['namespaces'],
            'breadcrumbs' => $directory['breadcrumbs'],
            'can_create' => $user !== null && $this->authorization->allows((int) $user['id'], 'wiki.edit'),
        ]));
    }

    public function recent(): Response
    {
        $user = $this->auth->user();
        return Response::html($this->views->render('@wiki/recent.twig', [
            'pages' => $this->pages->recentChanges($user ? (int) $user['id'] : null),
        ]));
    }

    public function map(): Response
    {
        $user = $this->auth->user();
        return Response::html($this->views->render('@wiki/map.twig', [
            'map' => $this->navigation->sitemap($this->pages->directory($user ? (int) $user['id'] : null)),
        ]));
    }

    public function search(Request $request): Response
    {
        $rawTerm = $request->query('q', '');
        $term = is_string($rawTerm) ? trim($rawTerm) : '';
        $results = [];
        $error = null;
        if ($rawTerm !== '') {
            try {
                $term = $this->input->searchTerm($rawTerm);
                $user = $this->auth->user();
                $results = $this->pages->search($term, $user ? (int) $user['id'] : null);
            } catch (RuntimeException $exception) {
                $error = $this->error($exception->getMessage());
            }
        }
        return Response::html($this->views->render('@wiki/search.twig', [
            'term' => $term,
            'results' => $results,
            'error' => $error,
            'searched' => $term !== '' && $error === null,
        ]), $error === null ? 200 : 422);
    }

    public function show(Request $request): Response
    {
        try {
            $path = $this->input->path($request->attribute('path'));
        } catch (RuntimeException) {
            return Response::html($this->translate('error.page_not_found', 'Wiki page not found.'), 404);
        }
        $user = $this->auth->user();
        $canEdit = $user !== null && $this->authorization->allows((int) $user['id'], 'wiki.edit');
        $page = $this->pages->publishedByPath($path);
        if ($page === null) {
            $canonical = $this->pages->byPath($path);
            if ($canonical === null) {
                $alias = $this->pages->publishedByAlias($path);
                if ($alias !== null && $this->pages->canView($alias, $user ? (int) $user['id'] : null)) return Response::redirect('/wiki/' . $alias['path'], 301);
                try {
                    $namespace = $this->input->namespace($path);
                    $start = $this->pages->publishedByPath($namespace . ':start');
                    if ($start !== null && $this->pages->canView($start, $user ? (int) $user['id'] : null)) return $this->renderPage($start, $user, $canEdit);
                    $directory = $this->navigation->directory($this->pages->directory($user ? (int) $user['id'] : null), $namespace);
                    if ($directory['pages'] !== [] || $directory['namespaces'] !== []) return Response::html($this->views->render('@wiki/index.twig', [
                        'namespace' => $namespace, 'pages' => $directory['pages'], 'namespaces' => $directory['namespaces'],
                        'breadcrumbs' => $directory['breadcrumbs'], 'can_create' => $canEdit,
                    ]));
                } catch (RuntimeException) {
                }
            }
            $draft = $canEdit ? $this->pages->byPath($path) : null;
            return Response::html($this->views->render('@wiki/missing.twig', [
                'path' => $path,
                'action_url' => $draft !== null
                    ? '/admin/wiki/' . (int) $draft['id'] . '/edit'
                    : ($canEdit ? '/admin/wiki/new?path=' . rawurlencode($path) : null),
                'draft_exists' => $draft !== null,
            ]), 404);
        }
        if (! $this->pages->canView($page, $user ? (int) $user['id'] : null)) {
            return $user === null ? Response::redirect('/login') : Response::html($this->translate('error.page_unavailable_account', 'This wiki page is not available for your account.'), 403);
        }
        return $this->renderPage($page, $user, $canEdit);
    }

    /** @param array<string,mixed> $page @param array<string,mixed>|null $user */
    private function renderPage(array $page, ?array $user, bool $canEdit): Response
    {
        $path = (string) $page['path'];
        $visiblePages = $this->pages->directory($user ? (int) $user['id'] : null);
        $visiblePaths = array_map(
            static fn (array $item): string => ($item['namespace'] === '' ? '' : $item['namespace'] . ':') . $item['slug'],
            $visiblePages,
        );
        $page['content_html'] = new Markup($this->linkPresenter->markMissing($this->markdownRenderer->render((string) $page['content']), $visiblePaths), 'UTF-8');
        $commentData = ['comments_available' => false];
        if ($this->comments !== null && (int) ($page['comments_enabled'] ?? 0) === 1) {
            $commentData = [
                'comments_available' => true,
                'comments' => $this->comments->for('wiki', (int) $page['id']),
                'comments_guests_allowed' => $this->comments->guestsAllowed(),
                'comments_csrf_token' => $this->csrf->token(),
                'comments_return_to' => '/wiki/' . $page['path'],
                'comments_message' => $this->session->pull('comments.message'),
                'comments_error' => $this->session->pull('comments.error'),
                'comments_user' => $user,
            ];
        }
        return Response::html($this->views->render('@wiki/show.twig', [
            'page' => $page,
            'edit_url' => $canEdit ? '/admin/wiki/' . (int) $page['id'] . '/edit' : null,
            'backlinks' => $this->pages->backlinks($path, $user ? (int) $user['id'] : null),
            'breadcrumbs' => $this->navigation->pageBreadcrumbs($path, (string) $page['title']),
            'attachments' => $this->attachments->forPage((int) $page['id']),
        ] + $commentData));
    }

    public function attachment(Request $request): Response
    {
        $id = filter_var($request->attribute('attachment'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) return Response::html($this->translate('error.attachment_not_found', 'Wiki attachment not found.'), 404);

        $user = $this->auth->user();
        $canEdit = $user !== null && $this->authorization->allows((int) $user['id'], 'wiki.edit');
        $attachment = $this->attachments->find((int) $id);
        if ($attachment === null || $attachment['deleted_at'] !== null) return Response::html($this->translate('error.attachment_not_found', 'Wiki attachment not found.'), 404);
        $published = $attachment['status'] === 'published' && $attachment['published_at'] !== null
            && (string) $attachment['published_at'] <= gmdate('Y-m-d H:i:s');
        if (! $published && ! $canEdit) return Response::html($this->translate('error.attachment_not_found', 'Wiki attachment not found.'), 404);
        if ($published && ! $canEdit && ! $this->pages->canView($attachment, $user ? (int) $user['id'] : null)) {
            return $user === null ? Response::redirect('/login') : Response::html($this->translate('error.attachment_unavailable_account', 'This Wiki attachment is not available for your account.'), 403);
        }

        try {
            $path = $this->attachments->path((string) $attachment['stored_name']);
            if ($request->query('inline') === '1' && in_array((string) $attachment['mime_type'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                return new Response(static function () use ($path): void { readfile($path); }, 200, [
                    'Content-Type' => (string) $attachment['mime_type'],
                    'Content-Length' => (string) filesize($path),
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
            return Response::download(
                $path,
                (string) $attachment['original_name'],
                (string) $attachment['mime_type'],
            );
        } catch (RuntimeException) {
            return Response::html($this->translate('error.attachment_unavailable', 'Wiki attachment file is unavailable.'), 404);
        }
    }

    private function error(string $message): string
    {
        $keys = [
            'Invalid Wiki search term.' => 'search_invalid',
            'Wiki searches must contain between 2 and 100 characters.' => 'search_length',
        ];
        return isset($keys[$message]) ? $this->translate('error.' . $keys[$message], $message) : $message;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate('wiki::' . $key) ?? $fallback;
    }
}
