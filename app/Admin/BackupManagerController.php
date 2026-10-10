<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Backup\BackupOperationLock;
use NovaNuke\Core\Backup\BackupExportBundle;
use NovaNuke\Core\Backup\BackupSetCoordinator;
use NovaNuke\Core\Backup\BackupSetId;
use NovaNuke\Core\Backup\BackupSetStatus;
use NovaNuke\Core\Backup\BackupVerifier;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;
use Throwable;

final class BackupManagerController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly BackupSetStatus $status,
        private readonly BackupSetCoordinator $coordinator,
        private readonly BackupVerifier $verifier,
        private readonly string $backupDirectory,
        private readonly ?Translator $translator = null,
    ) {}

    public function index(): Response
    {
        $guard = $this->guard();
        if ($guard !== null) return $guard;
        return $this->view();
    }

    public function create(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return $this->error('admin.error.csrf', 'Invalid or expired CSRF token.', 419);
        if ((string) $request->input('confirm') !== '1') return $this->view(null, $this->translate('backup.error.confirm', 'Confirm backup creation before continuing.'), 422);

        $lock = new BackupOperationLock($this->backupDirectory);
        try {
            $lock->acquire();
            if (function_exists('set_time_limit')) @set_time_limit(300);
            // Web requests have no safe secret input channel in this release. The
            // operation is intentionally plaintext and the UI warns the operator.
            $result = $this->coordinator->create(null);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'backup.create', 'backup-set', $result['backup_set_id'], ['encrypted' => false], $request->ip());
            $this->session->put('backup.message', $this->translate('backup.message.created', 'Backup set created.'));
            return Response::redirect('/admin/backups', 303);
        } catch (Throwable $error) {
            return $this->view(null, $this->safeError($error), 422);
        } finally { $lock->release(); }
    }

    public function verify(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return $this->error('admin.error.csrf', 'Invalid or expired CSRF token.', 419);
        $id = (string) $request->attribute('id');
        try { $id = BackupSetId::normalize($id); } catch (Throwable) { return $this->error('backup.error.invalid_id', 'Invalid backup set.', 404); }
        $manifest = rtrim($this->backupDirectory, '/\\') . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'manifest.json';
        if (! is_file($manifest) || is_link($manifest)) return $this->error('backup.error.not_found', 'Backup set not found.', 404);
        try {
            $this->verifier->verifyManifest($manifest);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'backup.verify', 'backup-set', $id, ['status' => 'valid'], $request->ip());
            $this->session->put('backup.message', $this->translate('backup.message.verified', 'Backup set verified.'));
            return Response::redirect('/admin/backups', 303);
        } catch (Throwable $error) {
            return $this->view(null, $this->safeError($error), 422);
        }
    }

    public function export(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) return $this->error('admin.error.csrf', 'Invalid or expired CSRF token.', 419);
        try { $id = BackupSetId::normalize((string) $request->attribute('id')); }
        catch (Throwable) { return $this->error('backup.error.invalid_id', 'Invalid backup set.', 404); }

        $destination = rtrim($this->backupDirectory, '/\\') . DIRECTORY_SEPARATOR . '.export-' . bin2hex(random_bytes(12)) . '.tar';
        try {
            $result = (new BackupExportBundle($this->backupDirectory))->create($id, $destination);
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'backup.export', 'backup-set', $id, ['encrypted' => $result['encrypted']], $request->ip());
            $filename = 'novanuke-backup-' . $id . '.tar';
            $size = filesize($destination);
            if ($size === false) throw new RuntimeException('Export bundle is unavailable.');
            return new Response(static function () use ($destination): void {
                try { readfile($destination); } finally { @unlink($destination); }
            }, 200, [
                'Content-Type' => 'application/x-tar',
                'Content-Length' => (string) $size,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (Throwable $error) {
            @unlink($destination);
            return $this->view(null, $this->safeError($error), 422);
        }
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        return $this->authorization->allows((int) $user['id'], 'backup.manage') ? null : $this->error('admin.error.forbidden', 'Forbidden', 403);
    }

    private function view(?string $error = null, ?string $message = null, int $status = 200): Response
    {
        $rows = [];
        foreach ($this->status->inspect() as $item) {
            $rows[] = [
                'id' => (string) ($item['backup_set_id'] ?? ''),
                'timestamp' => (string) ($item['timestamp'] ?? ''),
                'status' => (string) ($item['status'] ?? 'unknown'),
                'encrypted' => (bool) ($item['encrypted'] ?? false),
                'components' => array_values(array_map('strval', (array) ($item['components'] ?? []))),
                'bytes' => (int) ($item['bytes'] ?? 0),
                'detail' => in_array($item['status'] ?? '', ['valid', 'secret-required'], true) ? (string) ($item['detail'] ?? '') : $this->translate('backup.status.integrity_failed', 'Integrity verification failed.'),
            ];
        }
        return Response::html($this->views->render('@admin-core/admin/backups.twig', [
            'backups' => $rows, 'csrf_token' => $this->csrf->token(),
            'message' => $message ?? $this->session->pull('backup.message'), 'error' => $error,
        ]), $status);
    }

    private function safeError(Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'passphrase')) return $this->translate('backup.error.secret_required', 'An encryption secret is required to verify this backup.');
        if (str_contains($message, 'already running')) return $this->translate('backup.error.concurrent', 'Another backup operation is already running.');
        return $this->translate('backup.error.operation', 'The backup operation could not be completed.');
    }

    private function error(string $key, string $fallback, int $status): Response { return Response::html($this->translate($key, $fallback), $status); }
    private function translate(string $key, string $fallback): string { return $this->translator?->translate($key) ?? $fallback; }
}
