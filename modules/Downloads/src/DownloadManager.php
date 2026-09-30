<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Security\DatabaseRateLimiter;
use RuntimeException;

final class DownloadManager
{
    public function __construct(
        private readonly DownloadRepository $repository, private readonly DownloadUploadValidator $uploads,
        private readonly DownloadStorage $storage, private readonly AuthManager $auth,
        private readonly EventDispatcher $events, private readonly DatabaseRateLimiter $limiter,
        private readonly string $appKey,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function save(?int $id, array $data, ?array $file, int $actorId): int
    {
        $existing = $id === null ? null : $this->repository->download($id);
        if ($id !== null && $existing === null) throw new RuntimeException('Download not found.');
        $upload = $this->uploads->validate($file); $newStored = null;
        if ($data['source_type'] === 'local') {
            if ($upload !== null) { $stored = $this->storage->store($upload); $newStored = $stored['stored_name']; }
            elseif ($existing !== null && $existing['source_type'] === 'local' && $existing['stored_name'] !== null) {
                $stored = ['stored_name' => $existing['stored_name'], 'original_name' => $existing['original_name'], 'file_size' => $existing['file_size'], 'mime_type' => $existing['mime_type']];
            } else throw new RuntimeException('Select a local file to upload.');
            $data += $stored;
        } else {
            $data += ['stored_name' => null, 'original_name' => null, 'file_size' => null, 'mime_type' => null];
        }
        try { return $this->repository->save($id, $data, $actorId); }
        catch (\Throwable $error) {
            if ($newStored !== null) { try { $this->storage->remove($newStored); } catch (\Throwable) {} }
            throw $error;
        }
    }

    /** @return array{download:array<string,mixed>,path:?string,counted:bool} */
    public function prepare(string $slug, Request $request): array
    {
        $download = $this->repository->publicDownload($slug); if ($download === null) throw new RuntimeException('Download not found.');
        $user = $this->auth->user();
        if (! $this->repository->canView($download, $user ? (int) $user['id'] : null)) throw new RuntimeException($user === null ? 'Sign in to download this file.' : 'You do not have access to this download.');
        $path = $download['source_type'] === 'local' ? $this->storage->path((string) $download['stored_name']) : null;
        $identity = $user ? 'user:' . $user['id'] : 'guest:' . $request->ip() . '|' . $request->userAgent();
        $counted = $this->repository->recordDownload((int) $download['id'], $this->hash($identity));
        $this->events->dispatch(\NovaNuke\Core\Events\EventName::DOWNLOAD_COMPLETED, new DownloadCompleted((int) $download['id'], $counted));
        return ['download' => $download, 'path' => $path, 'counted' => $counted];
    }

    public function report(Request $request, int $id): int
    {
        $download = $this->repository->publicDownloadById($id); if ($download === null) throw new RuntimeException('Download not found.');
        $reason = trim((string) $request->input('reason')); if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) throw new RuntimeException('Report reason must contain 5-500 characters.');
        $user = $this->auth->user(); if (! $this->repository->canView($download, $user ? (int) $user['id'] : null)) throw new RuntimeException('Download not found.');
        $identity = $user ? 'user:' . $user['id'] : 'guest:' . $request->ip(); $rateKey = 'report|' . $identity;
        if ($this->limiter->tooManyAttempts($rateKey)) throw new RuntimeException('Too many reports. Please wait before trying again.');
        $report = $this->repository->report($id, $user ? (int) $user['id'] : null, $this->hash($identity), $reason); $this->limiter->hit($rateKey); return $report;
    }

    private function hash(string $value): string
    {
        if ($this->appKey === '') throw new RuntimeException('APP_KEY is required for download protection.');
        return hash_hmac('sha256', $value, $this->appKey);
    }
}
