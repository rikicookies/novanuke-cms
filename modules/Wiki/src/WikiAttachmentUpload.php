<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use finfo;
use RuntimeException;

final class WikiAttachmentUpload
{
    private const MAXIMUM_BYTES = 10485760;
    private const TYPES = [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
    ];

    /** @param array<string,mixed>|null $file @return array{temporary_path:string,original_name:string,extension:string,mime_type:string,file_size:int} */
    public function validate(?array $file): array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Select an attachment to upload.');
        }
        if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK) throw new RuntimeException('The attachment upload did not complete successfully.');

        $path = (string) ($file['tmp_name'] ?? '');
        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        $size = (int) ($file['size'] ?? -1);
        if ($path === '' || ! is_file($path) || $name === '' || $size < 1 || $size > self::MAXIMUM_BYTES || filesize($path) !== $size) {
            throw new RuntimeException('The attachment is missing, empty or larger than 10 MB.');
        }
        if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new RuntimeException('Invalid attachment filename.');

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$extension])) throw new RuntimeException('This attachment extension is not allowed.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! in_array(strtolower($mime), self::TYPES[$extension], true)) {
            throw new RuntimeException('Attachment content does not match its allowed MIME type.');
        }

        return [
            'temporary_path' => $path,
            'original_name' => $name,
            'extension' => $extension,
            'mime_type' => strtolower($mime),
            'file_size' => $size,
        ];
    }
}
