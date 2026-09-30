<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use finfo;
use RuntimeException;

final class DownloadUploadValidator
{
    private const TYPES = [
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        'pdf' => ['application/pdf'], 'txt' => ['text/plain'],
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'],
    ];

    /** @param array<string,mixed>|null $file */
    public function validate(?array $file, int $maximumBytes = 52428800): ?ValidatedUpload
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK) throw new RuntimeException('The file upload did not complete successfully.');
        $path = (string) ($file['tmp_name'] ?? '');
        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        $size = (int) ($file['size'] ?? -1);
        if ($path === '' || ! is_file($path) || $name === '' || $size < 1 || $size > $maximumBytes || filesize($path) !== $size) {
            throw new RuntimeException('Uploaded file is missing, empty or larger than 50 MB.');
        }
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$extension])) throw new RuntimeException('This file extension is not allowed.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! in_array(strtolower($mime), self::TYPES[$extension], true)) {
            throw new RuntimeException('File content does not match an allowed MIME type.');
        }
        if ($extension === 'zip') {
            $handle = fopen($path, 'rb');
            $signature = $handle === false ? false : fread($handle, 4);
            if (is_resource($handle)) fclose($handle);
            if (! is_string($signature) || ! in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
                throw new RuntimeException('ZIP archive signature is invalid.');
            }
        }
        if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new RuntimeException('Invalid original filename.');
        return new ValidatedUpload($path, $name, $extension, strtolower($mime), $size);
    }
}
