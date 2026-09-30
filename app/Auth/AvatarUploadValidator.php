<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use finfo;
use RuntimeException;

final class AvatarUploadValidator
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** @param array<string,mixed>|null $file */
    public function validate(?array $file, int $maximumBytes = 2097152): ValidatedAvatar
    {
        if ($file === null || (int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK) throw new RuntimeException('Select a JPEG, PNG or WebP image.');
        $path = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? -1);
        if ($path === '' || ! is_file($path) || $size < 1 || $size > $maximumBytes || filesize($path) !== $size) {
            throw new RuntimeException('Avatar must be a non-empty image no larger than 2 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $dimensions = @getimagesize($path);
        if (! is_string($mime) || ! isset(self::TYPES[$mime]) || ! is_array($dimensions)
            || ($dimensions['mime'] ?? '') !== $mime || $dimensions[0] < 32 || $dimensions[1] < 32
            || $dimensions[0] > 2048 || $dimensions[1] > 2048) {
            throw new RuntimeException('Avatar content or dimensions are invalid. Use 32–2048 pixel JPEG, PNG or WebP images.');
        }
        return new ValidatedAvatar($path, self::TYPES[$mime], $mime, $size);
    }
}
