<?php

declare(strict_types=1);

namespace Modules\News\src;

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

final class AdminNewsController
{
    public function __construct(
        private readonly NewsRepository $news,
        private readonly NewsInput $input,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly ?MediaLibraryInterface $media = null,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        $message = $this->session->pull('news.message');
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $category = filter_var($request->query('category'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $sort = in_array($request->query('sort'), ['newest', 'oldest'], true) ? (string) $request->query('sort') : 'newest';
        return Response::html($this->views->render('@admin-news/index.twig', [
            'articles' => $this->news->adminArticles($search, $category ? (int) $category : null, $sort), 'categories' => $this->news->categories(),
            'topics' => $this->news->topics(), 'csrf_token' => $this->csrf->token(),
            'message' => is_string($message) ? $message : null,
            'filters' => ['q' => $search, 'category' => $category, 'sort' => $sort],
            'can_publish' => $this->authorization->allows((int) $this->auth->user()['id'], 'news.publish'),
        ]));
    }

    public function create(): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        return $this->editor(null);
    }

    public function edit(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        try {
            $article = $this->news->article($this->routeId($request));
            return $article === null ? Response::html($this->translate('error.not_found', 'News article not found.'), 404) : $this->editor($article);
        } catch (RuntimeException) {
            return Response::html($this->translate('error.not_found', 'News article not found.'), 404);
        }
    }

    public function save(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        try {
            $actor = $this->auth->user();
            $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $data = $this->input->article($request->allInput(), $this->authorization->allows((int) $actor['id'], 'news.publish'));
            $articleId = $this->news->save($id ? (int) $id : null, $data, (int) $actor['id']);
            $action = $id ? \NovaNuke\Core\Events\EventName::CONTENT_UPDATED : \NovaNuke\Core\Events\EventName::CONTENT_CREATED;
            $event = new ContentChanged('news', $articleId, (int) $actor['id']);
            $this->events->dispatch($action, $event);
            $this->activity->log((int) $actor['id'], "news.article." . ($id ? 'updated' : 'created'), 'news', $articleId, ['status' => $data['status']], $request->ip());
            $this->session->put('news.message', $this->translate('admin.message.saved', 'News article saved.'));
            return Response::redirect('/admin/news', 303);
        } catch (RuntimeException $error) {
            return $this->editor($request->allInput(), $this->error($error->getMessage()), 422);
        }
    }

    public function delete(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        if ($request->input('confirm_delete') !== '1') return Response::html($this->translate('admin.error.confirm_delete', 'Confirm deletion.'), 422);
        try {
            $id = $this->routeId($request);
            $this->news->delete($id);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'news.article.deleted', 'news', $id, [], $request->ip());
            $this->session->put('news.message', $this->translate('admin.message.deleted', 'News article moved to deleted state.'));
            return Response::redirect(SafeReturnPath::choose($request->input('return_to'), ['/news'], '/admin/news'), 303);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
    }

    public function bulk(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $ids = $request->input('ids', []);
        if (! is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            $this->session->put('news.message', $this->translate('admin.message.select_one', 'Select at least one article.'));
            return Response::redirect('/admin/news', 303);
        }
        $action = (string) $request->input('bulk_action', '');
        $publishActions = ['publish', 'unpublish'];
        if (in_array($action, $publishActions, true) && ! $this->authorization->allows((int) $this->auth->user()['id'], 'news.publish')) {
            return Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
        }
        try {
            $count = $this->news->bulkUpdate($ids, $action);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
        $actor = $this->auth->user();
        $this->activity->log((int) $actor['id'], 'news.article.bulk_' . $action, 'news', null, ['count' => $count], $request->ip());
        $this->session->put('news.message', str_replace('{count}', (string) $count, $this->translate('admin.message.bulk_updated', '{count} article(s) updated.')));
        return Response::redirect('/admin/news', 303);
    }

    public function taxonomy(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        try {
            $type = (string) $request->attribute('type');
            $id = $this->news->saveTaxonomy($type, $this->input->taxonomy($request->allInput()));
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], "news.{$type}.created", $type, $id, [], $request->ip());
            $key = $type === 'category' ? 'admin.message.category_created' : 'admin.message.topic_created';
            $fallback = $type === 'category' ? 'Category created.' : 'Topic created.';
            $this->session->put('news.message', $this->translate($key, $fallback));
            return Response::redirect('/admin/news', 303);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
    }

    public function taxonomyEdit(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        try {
            $type = $this->taxonomyType($request);
            $taxonomy = $this->news->taxonomy($type, $this->routeId($request));
            if ($taxonomy === null) return Response::html($this->translate('admin.error.taxonomy_not_found', 'News taxonomy not found.'), 404);
            return $this->taxonomyEditor($type, $taxonomy);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 404);
        }
    }

    public function taxonomyUpdate(Request $request): Response
    {
        if ($guard = $this->guard('news.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        try {
            $type = $this->taxonomyType($request);
            $id = $this->routeId($request);
            $current = $this->news->taxonomy($type, $id);
            if ($current === null) return Response::html($this->translate('admin.error.taxonomy_not_found', 'News taxonomy not found.'), 404);
            $data = $this->input->taxonomyUpdate($request->allInput(), (string) $current['slug'], $type === 'category');
            $this->news->updateTaxonomy($type, $id, $data);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'news.' . $type . '.updated', 'news_' . $type, $id, [], $request->ip());
            $this->session->put('news.message', $this->translate('admin.message.taxonomy_updated', 'News taxonomy updated.'));
            return Response::redirect('/admin/news', 303);
        } catch (RuntimeException $error) {
            $type = $request->attribute('type') === 'category' ? 'category' : 'topic';
            $existing = $this->news->taxonomy($type, (int) ($request->attribute('id') ?: 0));
            $taxonomy = $existing === null ? array_merge(['id' => (int) ($request->attribute('id') ?: 0)], $request->allInput()) : array_merge($existing, $request->allInput(), ['id' => $existing['id'], 'slug' => $existing['slug']]);
            return $this->taxonomyEditor($type, $taxonomy, $this->error($error->getMessage()), 422);
        }
    }

    private function editor(?array $article, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('@admin-news/edit.twig', [
            'article' => $article ?? [], 'categories' => $this->news->categories(), 'topics' => $this->news->topics(),
            'can_publish' => $this->authorization->allows((int) $this->auth->user()['id'], 'news.publish'),
            'csrf_token' => $this->csrf->token(), 'error' => $error,
            'media_available' => $this->media !== null, 'media_images' => $this->media?->all() ?? [],
        ]), $status);
    }

    private function taxonomyEditor(string $type, array $taxonomy, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('@admin-news/taxonomy-edit.twig', [
            'taxonomy' => $taxonomy, 'type' => $type, 'is_category' => $type === 'category',
            'parent_options' => $type === 'category' ? $this->news->categoryParentOptions((int) ($taxonomy['id'] ?? 0)) : [],
            'csrf_token' => $this->csrf->token(), 'error' => $error,
        ]), $status);
    }

    private function taxonomyType(Request $request): string
    {
        $type = (string) $request->attribute('type');
        if (! in_array($type, ['category', 'topic'], true)) throw new RuntimeException('Invalid taxonomy type.');

        return $type;
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
        if ($id === false) throw new RuntimeException('Invalid news identifier.');
        return (int) $id;
    }

    private function error(string $message): string
    {
        $keys = [
            'News article not found.' => 'not_found', 'Invalid news identifier.' => 'invalid_identifier',
            'Title is required and must not exceed 200 characters.' => 'title', 'Slug must use lowercase words separated by hyphens.' => 'slug',
            'Invalid publication status.' => 'status', 'You may save drafts but do not have permission to publish.' => 'publish_permission',
            'Scheduled news requires a future publication date.' => 'schedule_future', 'Use scheduled status for a future publication date.' => 'future_status',
            'Article content is required.' => 'content_required', 'Article content must not exceed 1,000,000 characters.' => 'content_length',
            'Invalid news audience.' => 'audience', 'Enter a valid name and lowercase slug.' => 'taxonomy',
            'Invalid category or topic.' => 'taxonomy_selection', 'Featured image must be a safe path below /uploads/.' => 'image',
            'Invalid publication date.' => 'date', 'Tags must not exceed 80 characters.' => 'tag_length',
            'Use no more than 20 tags.' => 'tag_count', 'The news slug is already in use.' => 'slug_used',
            'Invalid taxonomy type.' => 'taxonomy_type', 'That taxonomy slug is already in use.' => 'taxonomy_slug_used',
            'Selected category or topic does not exist.' => 'taxonomy_missing',
            'News taxonomy not found.' => 'taxonomy_not_found', 'Enter a valid taxonomy name.' => 'taxonomy_name',
            'Taxonomy slugs are read-only.' => 'taxonomy_slug_readonly', 'Selected parent category does not exist.' => 'parent_missing',
            'A category cannot be its own parent or descendant.' => 'taxonomy_cycle', 'Category hierarchy contains a cycle.' => 'taxonomy_cycle',
        ];
        if (isset($keys[$message])) return $this->translate('admin.error.' . $keys[$message], $message);
        if (str_starts_with($message, 'Text must not exceed ')) return $this->translate('admin.error.text_length', $message);
        return $message;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate('news::' . $key) ?? $fallback;
    }
}
