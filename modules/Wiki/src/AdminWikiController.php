<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class AdminWikiController
{
    public function __construct(
        private readonly WikiRepository $pages,
        private readonly WikiInput $input,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly WikiMarkdownRenderer $markdownRenderer,
        private readonly WikiRevisionComparator $revisionComparator,
        private readonly WikiMarkdownFile $markdownFile,
        private readonly WikiAttachmentManager $attachments,
        private readonly WikiArchive $archive,
        private readonly WikiFolderImport $folderImport,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        return $this->listing($request);
    }

    public function namespaces(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        $namespace = $request->query('namespace', '');
        try {
            $namespace = $this->input->namespace($namespace);
        } catch (RuntimeException) {
            $namespace = '';
        }
        return $this->namespaceManager(form: [
            'old_namespace' => $namespace,
            'include_descendants' => false,
        ]);
    }

    public function moveNamespace(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        try {
            $old = $this->input->namespace($request->input('old_namespace'));
            $new = $this->input->namespace($request->input('new_namespace'));
            if ($old === '' || $new === '') throw new RuntimeException('Root namespace moves are not supported.');
            if ($old === $new) throw new RuntimeException('Choose a different destination namespace.');
            if (str_starts_with($new, $old . ':')) throw new RuntimeException('A namespace cannot be moved inside itself.');
            $includeDescendants = $request->input('include_descendants') === '1';
            $plan = $this->pages->namespaceMovePlan($old, $new, $includeDescendants);
            if ($plan['affected_count'] === 0) throw new RuntimeException('Wiki namespace has no pages to move.');
            if ($request->input('confirm_move') !== '1') return $this->namespaceManager($plan, form: [
                'old_namespace' => $old, 'new_namespace' => $new, 'include_descendants' => $includeDescendants,
            ]);
            if ($plan['collisions'] !== []) throw new RuntimeException('Resolve namespace path collisions before moving pages.');
            $plan = $this->pages->renameNamespace($old, $new, $includeDescendants);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'wiki.namespace.moved', 'wiki_namespace', null, [
                'old_namespace' => $old, 'new_namespace' => $new,
                'affected_count' => $plan['affected_count'], 'include_descendants' => $includeDescendants,
            ], $request->ip());
            $this->session->put('wiki.namespace_message', $this->translate('admin.namespaces.moved', 'Namespace moved. Review Missing Links for references to old Wiki paths.', ['count' => $plan['affected_count']]));
            return Response::redirect('/admin/wiki/namespaces', 303);
        } catch (RuntimeException $error) {
            return $this->namespaceManager(null, $this->namespaceError($error->getMessage()), 422, [
                'old_namespace' => (string) $request->input('old_namespace', ''),
                'new_namespace' => (string) $request->input('new_namespace', ''),
                'include_descendants' => $request->input('include_descendants') === '1',
            ]);
        }
    }

    /** @param array<string,mixed>|null $plan @param array<string,mixed> $form */
    private function namespaceManager(?array $plan = null, ?string $error = null, int $status = 200, array $form = []): Response
    {
        return Response::html($this->views->render('@wiki/admin/namespaces.twig', [
            'namespaces' => $this->pages->namespaceSummary(), 'plan' => $plan, 'error' => $error, 'form' => $form,
            'message' => $this->session->pull('wiki.namespace_message'), 'csrf_token' => $this->csrf->token(),
        ]), $status);
    }

    public function missingLinks(): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        return Response::html($this->views->render('@wiki/admin/missing-links.twig', [
            'missing_links' => $this->pages->missingLinks(),
        ]));
    }

    private function listing(?Request $request = null, ?string $importError = null, int $status = 200): Response
    {
        $user = $this->auth->user();
        $filters = $this->filters($request);
        $result = $this->pages->adminListing(
            $filters['page'], $filters['per_page'], $filters['status'], $filters['namespace'], $filters['q'], $filters['sort'],
        );
        return Response::html($this->views->render('@wiki/admin/index.twig', [
            'pages' => $result['items'],
            'result' => $result,
            'filters' => $filters,
            'namespaces' => $this->pages->adminNamespaces(),
            'return_query' => $this->queryString($filters),
            'message' => $this->session->pull('wiki.message'),
            'import_error' => $importError,
            'csrf_token' => $this->csrf->token(),
            'can_publish' => $user !== null && $this->authorization->allows((int) $user['id'], 'wiki.publish'),
        ]), $status);
    }

    public function create(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        $path = '';
        try {
            if ($request->query('path') !== null) $path = $this->input->path($request->query('path'));
        } catch (RuntimeException) {
        }
        return $this->editor(['path' => $path]);
    }

    public function edit(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        $page = $this->pages->find($this->routeId($request));
        return $page === null ? Response::html($this->translate('error.page_not_found', 'Wiki page not found.'), 404) : $this->editor($page);
    }

    public function save(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $actor = $this->auth->user();
        try {
            $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $data = $this->input->page($request->allInput(), $this->authorization->allows((int) $actor['id'], 'wiki.publish'));
            $pageId = $this->pages->save($id ? (int) $id : null, $data, (int) $actor['id']);
            $event = new WikiPageChanged($pageId, (string) $data['path'], (int) $actor['id']);
            $this->events->dispatch($id ? \NovaNuke\Core\Events\EventName::CONTENT_UPDATED : \NovaNuke\Core\Events\EventName::CONTENT_CREATED, $event);
            $this->activity->log((int) $actor['id'], 'wiki.page.' . ($id ? 'updated' : 'created'), 'wiki_page', $pageId, ['path' => $data['path'], 'status' => $data['status']], $request->ip());
            $this->session->put('wiki.message', $this->translate('admin.message.saved', 'Wiki page saved.'));
            return Response::redirect('/admin/wiki', 303);
        } catch (RuntimeException $error) {
            return $this->editor($request->allInput(), $this->error($error->getMessage()), 422);
        }
    }

    public function preview(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);

        try {
            $page = $this->input->page(array_replace($request->allInput(), ['status' => 'draft']), false);
            $page['content_html'] = new \Twig\Markup($this->markdownRenderer->render((string) $page['content']), 'UTF-8');

            return new Response($this->views->render('@wiki/admin/preview.twig', [
                'page' => $page,
            ]), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
    }

    public function import(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        try {
            $draft = $this->markdownFile->import($request->file('markdown_file'));
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'wiki.markdown.opened', 'wiki_page', null, [
                'suggested_path' => $draft['path'],
                'bytes' => strlen($draft['content']),
            ], $request->ip());
            return $this->editor($draft, notice: $this->translate('admin.message.markdown_imported', 'Markdown imported into a draft. Review it before saving.'));
        } catch (RuntimeException $error) {
            return $this->listing($request, $this->error($error->getMessage()), 422);
        }
    }

    public function importFolder(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        if ($request->input('confirm_import') !== '1') return Response::html($this->translate('admin.error.confirm_import', 'Confirm the Wiki folder import.'), 422);

        try {
            $batch = $this->folderImport->drafts(
                $request->file('markdown_files'),
                $request->input('markdown_paths', []),
                $request->input('markdown_expected_count'),
            );
            $existing = array_fill_keys($this->pages->existingPaths(), true);
            $actor = $this->auth->user();
            $created = 0;
            $conflicts = 0;
            foreach ($batch['drafts'] as $draft) {
                if (isset($existing[$draft['path']])) {
                    $conflicts++;
                    continue;
                }
                $data = $this->input->page($draft, false);
                $pageId = $this->pages->save(null, $data, (int) $actor['id']);
                $this->events->dispatch(\NovaNuke\Core\Events\EventName::CONTENT_CREATED, new WikiPageChanged($pageId, (string) $data['path'], (int) $actor['id']));
                $existing[$data['path']] = true;
                $created++;
            }
            $this->activity->log((int) $actor['id'], 'wiki.folder.imported', 'wiki_page', null, [
                'created' => $created,
                'conflicts' => $conflicts,
                'ignored' => $batch['ignored'],
            ], $request->ip());
            $this->session->put('wiki.message', $this->translate(
                'admin.message.folder_imported',
                sprintf('Imported %d Markdown drafts. Skipped %d existing paths and %d non-Markdown files.', $created, $conflicts, $batch['ignored']),
                ['created' => $created, 'conflicts' => $conflicts, 'ignored' => $batch['ignored']],
            ));
            return Response::redirect('/admin/wiki', 303);
        } catch (RuntimeException $error) {
            return $this->listing($request, $this->error($error->getMessage()), 422);
        }
    }

    public function export(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        try {
            $page = $this->pages->find($this->routeId($request));
        } catch (RuntimeException) {
            $page = null;
        }
        if ($page === null) return Response::html($this->translate('error.page_not_found', 'Wiki page not found.'), 404);
        $content = (string) $page['content'];
        $filename = $this->markdownFile->filename((string) $page['path']);
        return new Response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function exportAll(): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        try {
            $path = $this->archive->create($this->pages->exportPages());
            $filename = 'novanuke-wiki-' . gmdate('Ymd-His') . '.zip';
            return new Response(static function () use ($path): void {
                try {
                    readfile($path);
                } finally {
                    if (is_file($path)) unlink($path);
                }
            }, 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) filesize($path),
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
    }

    public function bulk(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        if ($request->input('confirm_bulk') !== '1') return Response::html($this->translate('admin.error.confirm_bulk', 'Confirm the bulk Wiki action.'), 422);

        try {
            $action = $request->input('bulk_action');
            if (! is_string($action) || ! in_array($action, [
                'publish', 'draft', 'audience_public', 'audience_member', 'audience_vip', 'delete',
            ], true)) throw new RuntimeException('Select a valid bulk Wiki action.');
            if (in_array($action, ['publish', 'audience_public', 'audience_member', 'audience_vip'], true)) {
                if ($guard = $this->guard('wiki.publish')) return $guard;
            }

            $actor = $this->auth->user();
            $changed = $this->pages->bulkChange(
                $this->selectedPageIds($request->input('page_ids', [])),
                $action,
                (int) $actor['id'],
            );
            if ($action !== 'delete') {
                foreach ($changed as $page) {
                    $this->events->dispatch(\NovaNuke\Core\Events\EventName::CONTENT_UPDATED, new WikiPageChanged(
                        $page['id'], $page['path'], (int) $actor['id'],
                    ));
                }
            }
            $this->activity->log((int) $actor['id'], 'wiki.pages.bulk', 'wiki_page', null, [
                'action' => $action,
                'count' => count($changed),
            ], $request->ip());
            $count = count($changed);
            $this->session->put('wiki.message', $this->translate('admin.message.bulk_applied', sprintf('Bulk action applied to %d Wiki pages.', $count), ['count' => $count]));
            return Response::redirect('/admin/wiki?' . $this->queryString($this->filters($request)), 303);
        } catch (RuntimeException $error) {
            return $this->listing($request, $this->error($error->getMessage()), 422);
        }
    }

    public function delete(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        if ($request->input('confirm_delete') !== '1') return Response::html($this->translate('admin.error.confirm_delete', 'Confirm deletion.'), 422);
        try {
            $id = $this->routeId($request);
            $this->pages->delete($id);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'wiki.page.deleted', 'wiki_page', $id, [], $request->ip());
            $this->session->put('wiki.message', $this->translate('admin.message.deleted', 'Wiki page moved to deleted state.'));
            return Response::redirect('/admin/wiki', 303);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
    }

    public function uploadAttachment(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        $pageId = $this->routeId($request);
        if ($this->pages->find($pageId) === null) return Response::html($this->translate('error.page_not_found', 'Wiki page not found.'), 404);
        $actor = $this->auth->user();
        try {
            $attachmentId = $this->attachments->store($pageId, (int) $actor['id'], $request->file('attachment'));
            $this->activity->log((int) $actor['id'], 'wiki.attachment.created', 'wiki_attachment', $attachmentId, ['wiki_page_id' => $pageId], $request->ip());
            $this->session->put('wiki.attachment_message', $this->translate('admin.message.attachment_uploaded', 'Attachment uploaded.'));
        } catch (RuntimeException $error) {
            $this->session->put('wiki.attachment_error', $this->error($error->getMessage()));
        }
        return Response::redirect('/admin/wiki/' . $pageId . '/edit', 303);
    }

    public function deleteAttachment(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        if ($request->input('confirm_delete') !== '1') return Response::html($this->translate('admin.error.confirm_attachment_delete', 'Confirm attachment deletion.'), 422);
        $pageId = $this->routeId($request);
        $attachmentId = $this->routeId($request, 'attachment');
        try {
            $this->attachments->delete($pageId, $attachmentId);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'wiki.attachment.deleted', 'wiki_attachment', $attachmentId, ['wiki_page_id' => $pageId], $request->ip());
            $this->session->put('wiki.attachment_message', $this->translate('admin.message.attachment_deleted', 'Attachment deleted.'));
        } catch (RuntimeException $error) {
            $this->session->put('wiki.attachment_error', $this->error($error->getMessage()));
        }
        return Response::redirect('/admin/wiki/' . $pageId . '/edit', 303);
    }

    public function history(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        $page = $this->pages->find($this->routeId($request));
        if ($page === null) return Response::html($this->translate('error.page_not_found', 'Wiki page not found.'), 404);
        return Response::html($this->views->render('@wiki/admin/history.twig', [
            'page' => $page,
            'revisions' => $this->pages->revisions((int) $page['id']),
            'message' => $this->session->pull('wiki.message'),
        ]));
    }

    public function revision(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        $pageId = $this->routeId($request);
        $page = $this->pages->find($pageId);
        $revision = $this->pages->revision($pageId, $this->routeId($request, 'revision'));
        if ($page === null || $revision === null) return Response::html($this->translate('admin.error.revision_not_found', 'Wiki revision not found.'), 404);
        $revision['content_html'] = new \Twig\Markup($this->markdownRenderer->render((string) $revision['content']), 'UTF-8');
        return Response::html($this->views->render('@wiki/admin/revision.twig', [
            'page' => $page,
            'revision' => $revision,
            'csrf_token' => $this->csrf->token(),
            'can_restore' => $revision['status'] !== 'published'
                || $this->authorization->allows((int) $this->auth->user()['id'], 'wiki.publish'),
        ]));
    }

    public function compare(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        try {
            $pageId = $this->routeId($request);
            $fromId = $this->queryId($request, 'from');
            $toId = $this->queryId($request, 'to');
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
        if ($fromId === $toId) return Response::html($this->translate('admin.error.compare_different', 'Select two different Wiki revisions.'), 422);

        $page = $this->pages->find($pageId);
        $from = $this->pages->revision($pageId, $fromId);
        $to = $this->pages->revision($pageId, $toId);
        if ($page === null || $from === null || $to === null) return Response::html($this->translate('admin.error.revision_not_found', 'Wiki revision not found.'), 404);

        try {
            $diff = $this->revisionComparator->compare((string) $from['content'], (string) $to['content']);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
        return Response::html($this->views->render('@wiki/admin/compare.twig', [
            'page' => $page,
            'from' => $from,
            'to' => $to,
            'diff' => $diff,
        ]));
    }

    public function restore(Request $request): Response
    {
        if ($guard = $this->guard('wiki.edit')) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        if ($request->input('confirm_restore') !== '1') return Response::html($this->translate('admin.error.confirm_restore', 'Confirm restoration.'), 422);
        $actor = $this->auth->user();
        $pageId = $this->routeId($request);
        $revisionId = $this->routeId($request, 'revision');
        try {
            $restored = $this->pages->restore(
                $pageId, $revisionId, (int) $actor['id'],
                $this->authorization->allows((int) $actor['id'], 'wiki.publish'),
            );
            $this->events->dispatch(\NovaNuke\Core\Events\EventName::CONTENT_UPDATED, new WikiPageChanged($pageId, $restored['path'], (int) $actor['id']));
            $this->activity->log((int) $actor['id'], 'wiki.page.restored', 'wiki_page', $pageId, [
                'source_revision_id' => $revisionId,
                'new_revision_number' => $restored['revision_number'],
            ], $request->ip());
            $this->session->put('wiki.message', $this->translate('admin.message.revision_restored', 'Revision restored as a new current revision.'));
            return Response::redirect('/admin/wiki/' . $pageId . '/history', 303);
        } catch (RuntimeException $error) {
            return Response::html(htmlspecialchars($this->error($error->getMessage()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 422);
        }
    }

    /** @param array<string,mixed> $page */
    private function editor(array $page, ?string $error = null, int $status = 200, ?string $notice = null): Response
    {
        return Response::html($this->views->render('@wiki/admin/edit.twig', [
            'page' => $page,
            'error' => $error,
            'can_publish' => $this->authorization->allows((int) $this->auth->user()['id'], 'wiki.publish'),
            'csrf_token' => $this->csrf->token(),
            'notice' => $notice,
            'attachments' => isset($page['id']) ? $this->attachments->forPage((int) $page['id']) : [],
            'attachment_message' => $this->session->pull('wiki.attachment_message'),
            'attachment_error' => $this->session->pull('wiki.attachment_error'),
        ]), $status);
    }

    private function guard(string $permission): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return $this->authorization->allows((int) $user['id'], $permission) ? null : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
    }

    private function routeId(Request $request, string $attribute = 'id'): int
    {
        $id = filter_var($request->attribute($attribute), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid wiki page identifier.');
        return (int) $id;
    }

    private function queryId(Request $request, string $key): int
    {
        $id = filter_var($request->query($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid Wiki revision comparison.');
        return (int) $id;
    }

    /** @return list<int> */
    private function selectedPageIds(mixed $value): array
    {
        if (! is_array($value) || $value === [] || count($value) > 500) {
            throw new RuntimeException('Select between 1 and 500 Wiki pages.');
        }
        $ids = [];
        foreach ($value as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new RuntimeException('A selected Wiki page identifier is invalid.');
            $ids[(int) $id] = (int) $id;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array{page:int,per_page:int,status:string,namespace:?string,q:string,sort:string} */
    private function filters(?Request $request): array
    {
        $value = static fn (string $key, mixed $default = null): mixed => $request?->query($key) ?? $request?->input($key, $default);
        $page = filter_var($value('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $perPage = filter_var($value('per_page', 20), FILTER_VALIDATE_INT) ?: 20;
        if (! in_array($perPage, [10, 20, 50, 100], true)) $perPage = 20;
        $status = (string) ($value('status', 'all') ?? 'all');
        if (! in_array($status, ['all', 'published', 'draft'], true)) $status = 'all';
        $sort = (string) ($value('sort', 'path') ?? 'path');
        if (! in_array($sort, ['title_asc', 'title_desc', 'path', 'newest', 'oldest', 'updated'], true)) $sort = 'path';
        $q = mb_substr(trim((string) ($value('q', '') ?? '')), 0, 100);
        $namespace = null;
        $rawNamespace = $value('namespace');
        if ($rawNamespace !== null && $rawNamespace !== '__all__') {
            try {
                $namespace = $this->input->namespace($rawNamespace);
            } catch (RuntimeException) {
                $namespace = null;
            }
        }
        return ['page' => (int) $page, 'per_page' => $perPage, 'status' => $status, 'namespace' => $namespace, 'q' => $q, 'sort' => $sort];
    }

    /** @param array{page:int,per_page:int,status:string,namespace:?string,q:string,sort:string} $filters */
    private function queryString(array $filters): string
    {
        $query = [
            'page' => $filters['page'], 'per_page' => $filters['per_page'], 'status' => $filters['status'],
            'q' => $filters['q'], 'sort' => $filters['sort'],
        ];
        if ($filters['namespace'] !== null) $query['namespace'] = $filters['namespace'];
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function error(string $message): string
    {
        $keys = [
            'Wiki page not found.' => 'error.page_not_found', 'Invalid wiki page identifier.' => 'admin.error.invalid_identifier',
            'Title is required and must not exceed 200 characters.' => 'admin.error.title',
            'Markdown content is required and must not exceed 1,000,000 characters.' => 'admin.error.content',
            'Invalid wiki status.' => 'admin.error.status', 'You may save drafts but do not have permission to publish.' => 'admin.error.publish_permission',
            'Invalid wiki audience.' => 'admin.error.audience',
            'Wiki paths use lowercase words separated by hyphens and namespaces separated by colons.' => 'admin.error.path',
            'Each wiki path segment must not exceed 120 characters.' => 'admin.error.path_segment',
            'The wiki namespace must not exceed 190 characters.' => 'admin.error.namespace_length',
            'That wiki path is already in use.' => 'admin.error.path_used',
            'Select a Markdown .md file.' => 'admin.error.markdown_select',
            'The Markdown upload did not complete successfully.' => 'admin.error.markdown_upload',
            'Markdown files must be readable, non-empty and no larger than 1 MB.' => 'admin.error.markdown_size',
            'The Markdown filename is invalid.' => 'admin.error.markdown_filename',
            'Only files with the .md extension may be imported.' => 'admin.error.markdown_extension',
            'The uploaded file is not recognized as plain Markdown text.' => 'admin.error.markdown_mime',
            'The Markdown file could not be read.' => 'admin.error.markdown_read',
            'Markdown files must contain valid UTF-8 text without binary control characters.' => 'admin.error.markdown_utf8',
            'The Markdown file contains no content.' => 'admin.error.markdown_empty',
            'Select a folder containing Markdown files.' => 'admin.error.folder_select',
            'A Wiki folder import may contain between 1 and 100 files.' => 'admin.error.folder_count',
            'PHP accepted only part of the selected folder. Increase max_file_uploads or import a smaller folder.' => 'admin.error.folder_partial',
            'The Wiki folder upload is malformed.' => 'admin.error.folder_malformed',
            'The Wiki folder import cannot exceed 20 MB.' => 'admin.error.folder_size',
            'A Wiki folder path is invalid.' => 'admin.error.folder_path',
            'The selected folder contains no valid Markdown files.' => 'admin.error.folder_empty',
            'Wiki folders cannot be empty, nested more than 20 levels or contain traversal segments.' => 'admin.error.folder_depth',
            'Only Markdown files can become Wiki pages.' => 'admin.error.folder_markdown_only',
            'A Wiki folder or filename cannot form a valid namespace segment.' => 'admin.error.folder_segment',
            'Select a valid bulk Wiki action.' => 'admin.error.bulk_action', 'Invalid bulk Wiki action.' => 'admin.error.bulk_action',
            'Select between 1 and 500 Wiki pages.' => 'admin.error.bulk_selection',
            'A selected Wiki page identifier is invalid.' => 'admin.error.bulk_identifier',
            'A selected Wiki page no longer exists.' => 'admin.error.bulk_missing',
            'The PHP ZIP extension is required to export the Wiki archive.' => 'admin.error.archive_extension',
            'There are no Wiki pages to export.' => 'admin.error.archive_empty',
            'The Wiki archive cannot contain more than 5,000 pages.' => 'admin.error.archive_count',
            'The temporary Wiki archive could not be created.' => 'admin.error.archive_temporary',
            'The Wiki archive could not be opened.' => 'admin.error.archive_open',
            'A Wiki page could not be added to the archive.' => 'admin.error.archive_add',
            'The Wiki archive could not be finalized.' => 'admin.error.archive_finalize',
            'A Wiki page has an invalid archive path.' => 'admin.error.archive_path',
            'Select an attachment to upload.' => 'admin.error.attachment_select',
            'The attachment upload did not complete successfully.' => 'admin.error.attachment_upload',
            'The attachment is missing, empty or larger than 10 MB.' => 'admin.error.attachment_size',
            'Invalid attachment filename.' => 'admin.error.attachment_filename',
            'This attachment extension is not allowed.' => 'admin.error.attachment_extension',
            'Attachment content does not match its allowed MIME type.' => 'admin.error.attachment_mime',
            'Private Wiki attachment storage is not writable.' => 'admin.error.attachment_storage_write',
            'Attachment source was not accepted by PHP.' => 'admin.error.attachment_source',
            'The Wiki attachment could not be stored.' => 'admin.error.attachment_store',
            'Wiki attachment not found.' => 'error.attachment_not_found',
            'Stored Wiki attachment filename is invalid.' => 'admin.error.attachment_stored_filename',
            'Wiki attachment file is unavailable.' => 'error.attachment_unavailable',
            'Stored Wiki attachment could not be removed.' => 'admin.error.attachment_remove',
            'Wiki revision not found.' => 'admin.error.revision_not_found',
            'Invalid Wiki revision comparison.' => 'admin.error.comparison_invalid',
            'These revisions contain too many lines to compare safely online.' => 'admin.error.comparison_lines',
            'These revisions contain too many changed lines to compare safely online.' => 'admin.error.comparison_changes',
            'You do not have permission to restore a published revision.' => 'admin.error.restore_permission',
            'The restored wiki path is already in use.' => 'admin.error.restore_path_used',
        ];
        if (isset($keys[$message])) return $this->translate($keys[$message], $message);
        if (str_starts_with($message, 'Multiple Markdown files resolve to the same Wiki path: ')) return $this->translate('admin.error.folder_duplicate', 'Multiple Markdown files resolve to the same Wiki path.');
        return $message;
    }

    /** @param array<string,scalar|null> $parameters */
    private function namespaceError(string $message): string
    {
        $keys = [
            'Invalid wiki namespace.' => 'invalid',
            'Each wiki namespace segment must not exceed 120 characters.' => 'segment',
            'Root namespace moves are not supported.' => 'root',
            'Choose a different destination namespace.' => 'same',
            'A namespace cannot be moved inside itself.' => 'inside_self',
            'Wiki namespace has no pages to move.' => 'empty',
            'Resolve namespace path collisions before moving pages.' => 'collisions',
            'Wiki namespace move has path collisions.' => 'collisions',
            'The destination namespace or page path is too long.' => 'destination_length',
            'A historical Wiki path is already assigned to another page.' => 'alias_collision',
        ];
        return isset($keys[$message]) ? $this->translate('admin.namespaces.error.' . $keys[$message], $message) : $message;
    }

    private function translate(string $key, string $fallback, array $parameters = []): string
    {
        return $this->translator?->translate('wiki::' . $key, $parameters) ?? $fallback;
    }
}
