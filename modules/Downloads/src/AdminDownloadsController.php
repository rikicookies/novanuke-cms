<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use NovaNuke\Auth\AuthManager;
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

final class AdminDownloadsController
{
    public function __construct(
        private readonly DownloadRepository $downloads, private readonly DownloadManager $manager,
        private readonly DownloadInput $input, private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization, private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf, private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        if ($guard = $this->guard()) return $guard;
        return Response::html($this->views->render('@admin-downloads/index.twig', [
            'downloads' => $this->downloads->adminDownloads(), 'categories' => $this->downloads->categories(),
            'reports' => $this->downloads->openReports(), 'csrf_token' => $this->csrf->token(),
            'message' => $this->session->pull('downloads.admin.message'), 'error' => $this->session->pull('downloads.admin.error'),
        ]));
    }

    public function create(): Response { if ($guard = $this->guard()) return $guard; return $this->editor(null); }

    public function edit(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard;
        try { $download = $this->downloads->download($this->id($request->attribute('id'))); return $download === null ? Response::html($this->translate('error.not_found', 'Download not found.'), 404) : $this->editor($download); }
        catch (RuntimeException) { return Response::html($this->translate('error.not_found', 'Download not found.'), 404); }
    }

    public function save(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard; if ($csrf = $this->csrf($request)) return $csrf;
        try {
            $actor = $this->auth->user(); $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $data = $this->input->download($request->allInput(), $this->authorization->allows((int) $actor['id'], 'downloads.publish'));
            $downloadId = $this->manager->save($id ? (int) $id : null, $data, $request->file('download_file'), (int) $actor['id']);
            $this->activity->log((int) $actor['id'], 'download.' . ($id ? 'updated' : 'created'), 'download', $downloadId, ['status' => $data['status']], $request->ip());
            return $this->redirect($this->translate('admin.message.saved', 'Download saved.'));
        } catch (RuntimeException $error) { return $this->editor($request->allInput(), $this->error($error->getMessage()), 422); }
    }

    public function category(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard; if ($csrf = $this->csrf($request)) return $csrf;
        try {
            $id = $this->downloads->saveCategory($this->input->category($request->allInput())); $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'download.category.created', 'download_category', $id, [], $request->ip()); return $this->redirect($this->translate('admin.message.category_created', 'Category created.'));
        } catch (RuntimeException $error) { return $this->redirect(null, $this->error($error->getMessage())); }
    }

    public function delete(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard; if ($csrf = $this->csrf($request)) return $csrf;
        if ($request->input('confirm_delete') !== '1') return $this->redirect(null, $this->translate('admin.error.confirm_delete', 'Confirm deletion.'));
        try { $id = $this->id($request->attribute('id')); $this->downloads->delete($id); $actor = $this->auth->user(); $this->activity->log((int) $actor['id'], 'download.deleted', 'download', $id, [], $request->ip()); $this->session->put('downloads.admin.message', $this->translate('admin.message.deleted', 'Download moved to deleted state.')); return Response::redirect(SafeReturnPath::choose($request->input('return_to'), ['/downloads'], '/admin/downloads'), 303); }
        catch (RuntimeException $error) { return $this->redirect(null, $this->error($error->getMessage())); }
    }

    public function resolve(Request $request): Response
    {
        if ($guard = $this->guard()) return $guard; if ($csrf = $this->csrf($request)) return $csrf;
        try { $id = $this->id($request->attribute('id')); $this->downloads->resolveReport($id); $actor = $this->auth->user(); $this->activity->log((int) $actor['id'], 'download.report.resolved', 'download_report', $id, [], $request->ip()); return $this->redirect($this->translate('admin.message.report_resolved', 'Report resolved.')); }
        catch (RuntimeException $error) { return $this->redirect(null, $this->error($error->getMessage())); }
    }

    private function editor(?array $download, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('@admin-downloads/edit.twig', [
            'download' => $download ?? [], 'categories' => $this->downloads->categories(), 'roles' => $this->downloads->roles(),
            'can_publish' => $this->authorization->allows((int) $this->auth->user()['id'], 'downloads.publish'),
            'csrf_token' => $this->csrf->token(), 'error' => $error,
        ]), $status);
    }

    private function guard(): ?Response { $user = $this->auth->user(); if ($user === null) return Response::redirect('/login'); return $this->authorization->allows((int) $user['id'], 'downloads.manage') ? null : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403); }
    private function csrf(Request $request): ?Response { return $this->csrf->validate($request->input('_token')) ? null : Response::html($this->translate('error.csrf', 'Invalid or expired CSRF token.'), 419); }
    private function id(mixed $value): int { $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($id === false) throw new RuntimeException('Invalid identifier.'); return (int) $id; }
    private function redirect(?string $message = null, ?string $error = null): Response { if ($message !== null) $this->session->put('downloads.admin.message', $message); if ($error !== null) $this->session->put('downloads.admin.error', $error); return Response::redirect('/admin/downloads', 303); }

    private function error(string $message): string
    {
        $keys = [
            'Download not found.' => 'not_found', 'Invalid identifier.' => 'invalid_identifier',
            'Name is required and must not exceed 200 characters.' => 'name', 'Slug must use lowercase words separated by hyphens.' => 'slug',
            'Description is required.' => 'description_required', 'Description must not exceed 100,000 characters.' => 'description_length',
            'Invalid download source type.' => 'source', 'Invalid publication status.' => 'status',
            'You may save drafts but do not have permission to publish.' => 'publish_permission',
            'Scheduled downloads require a future publication date.' => 'schedule_future',
            'Use scheduled status for a future publication date.' => 'future_status', 'Invalid access type.' => 'access',
            'Select at least one role for restricted downloads.' => 'roles_required',
            'Enter a valid category name and slug.' => 'category', 'Image must be a safe path below /uploads/.' => 'image',
            'External URL must use safe HTTP or HTTPS.' => 'external_url', 'Invalid publication date.' => 'date',
            'Select a local file to upload.' => 'local_file_required', 'The download slug is already in use.' => 'slug_used',
            'The category slug is already in use.' => 'category_slug_used', 'Selected category does not exist.' => 'category_missing',
            'One or more selected roles are invalid.' => 'roles_invalid', 'Open report not found.' => 'report_not_found',
            'The file upload did not complete successfully.' => 'upload_failed',
            'Uploaded file is missing, empty or larger than 50 MB.' => 'upload_size',
            'This file extension is not allowed.' => 'upload_extension',
            'File content does not match an allowed MIME type.' => 'upload_mime',
            'ZIP archive signature is invalid.' => 'upload_zip', 'Invalid original filename.' => 'upload_filename',
            'Upload source was not accepted by PHP.' => 'upload_source', 'The uploaded file could not be stored.' => 'upload_storage',
            'Stored upload could not be cleaned up.' => 'upload_cleanup',
        ];
        if (isset($keys[$message])) return $this->translate('admin.error.' . $keys[$message], $message);
        if (str_starts_with($message, 'Text must not exceed ')) return $this->translate('admin.error.text_length', $message);
        return $message;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate('downloads::' . $key) ?? $fallback;
    }
}
