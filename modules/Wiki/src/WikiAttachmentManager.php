<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use NovaNuke\Core\Storage\SafeStorageBoundary;
use PDO;
use RuntimeException;
use Throwable;

final class WikiAttachmentManager
{
    public function __construct(
        private readonly PDO $database,
        private readonly WikiAttachmentUpload $uploads,
        private readonly string $directory,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function forPage(int $pageId): array
    {
        $statement = $this->database->prepare(
            'SELECT id,original_name,mime_type,file_size,created_at FROM wiki_attachments WHERE wiki_page_id=:page_id ORDER BY original_name,id'
        );
        $statement->execute(['page_id' => $pageId]);
        $attachments = $statement->fetchAll();
        foreach ($attachments as &$attachment) {
            $label = str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], (string) $attachment['original_name']);
            $url = '/wiki/attachments/' . (int) $attachment['id'];
            $attachment['markdown_link'] = '[' . $label . '](' . $url . ')';
            $attachment['markdown_image'] = in_array((string) $attachment['mime_type'], ['image/png', 'image/jpeg', 'image/webp'], true)
                ? '![' . $label . '](' . $url . '?inline=1)'
                : null;
        }
        unset($attachment);
        return $attachments;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT a.*,w.namespace,w.slug,w.status,w.audience,w.published_at,w.deleted_at '
            . 'FROM wiki_attachments a INNER JOIN wiki_pages w ON w.id=a.wiki_page_id WHERE a.id=:id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $attachment = $statement->fetch();
        return is_array($attachment) ? $attachment : null;
    }

    /** @param array<string,mixed>|null $file */
    public function store(int $pageId, int $actorId, ?array $file): int
    {
        $upload = $this->uploads->validate($file);
        try {
            SafeStorageBoundary::ensureDirectory($this->directory);
        } catch (RuntimeException) {
            throw new RuntimeException('Private Wiki attachment storage is not writable.');
        }
        if (! is_uploaded_file($upload['temporary_path'])) throw new RuntimeException('Attachment source was not accepted by PHP.');

        $storedName = bin2hex(random_bytes(20)) . '.' . $upload['extension'];
        $path = $this->directory . '/' . $storedName;
        if (! move_uploaded_file($upload['temporary_path'], $path)) throw new RuntimeException('The Wiki attachment could not be stored.');

        try {
            $statement = $this->database->prepare(
                'INSERT INTO wiki_attachments (wiki_page_id,uploaded_by,original_name,stored_name,mime_type,file_size,created_at) '
                . 'VALUES (:page_id,:actor_id,:original_name,:stored_name,:mime_type,:file_size,UTC_TIMESTAMP())'
            );
            $statement->execute([
                'page_id' => $pageId,
                'actor_id' => $actorId,
                'original_name' => $upload['original_name'],
                'stored_name' => $storedName,
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['file_size'],
            ]);
            return (int) $this->database->lastInsertId();
        } catch (Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }

    public function delete(int $pageId, int $attachmentId): void
    {
        $statement = $this->database->prepare('SELECT stored_name FROM wiki_attachments WHERE id=:id AND wiki_page_id=:page_id LIMIT 1');
        $statement->execute(['id' => $attachmentId, 'page_id' => $pageId]);
        $storedName = $statement->fetchColumn();
        if (! is_string($storedName)) throw new RuntimeException('Wiki attachment not found.');

        $path = $this->path($storedName);
        $delete = $this->database->prepare('DELETE FROM wiki_attachments WHERE id=:id AND wiki_page_id=:page_id');
        $delete->execute(['id' => $attachmentId, 'page_id' => $pageId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Wiki attachment not found.');
        if (! unlink($path)) throw new RuntimeException('Stored Wiki attachment could not be removed.');
    }

    public function path(string $storedName): string
    {
        if (! preg_match('/^[a-f0-9]{40}\.[a-z0-9]{2,5}$/', $storedName)) throw new RuntimeException('Stored Wiki attachment filename is invalid.');
        try {
            return SafeStorageBoundary::existingFile($this->directory, $storedName);
        } catch (RuntimeException) {
            throw new RuntimeException('Wiki attachment file is unavailable.');
        }
    }
}
