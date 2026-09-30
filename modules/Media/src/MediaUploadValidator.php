<?php

declare(strict_types=1);

namespace Modules\Media\src;

use finfo;
use RuntimeException;

final class MediaUploadValidator
{
    private const TYPES = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];

    /** @param array<string,mixed>|null $file */
    public function validate(?array $file, int $maximumBytes = 10485760): ValidatedMedia
    {
        if ($file === null || (int)($file['error'] ?? -1) !== UPLOAD_ERR_OK) throw new RuntimeException('Select a JPEG, PNG or WebP image.');
        $path=(string)($file['tmp_name'] ?? '');$name=basename(str_replace('\\','/',(string)($file['name'] ?? '')));$size=(int)($file['size'] ?? -1);
        if ($path==='' || !is_file($path) || $name==='' || $size<1 || $size>$maximumBytes || filesize($path)!==$size) throw new RuntimeException('Image must be non-empty and no larger than 10 MB.');
        if (mb_strlen($name)>255 || preg_match('/[\x00-\x1F\x7F]/',$name)) throw new RuntimeException('Original filename is invalid.');
        $extension=strtolower((string)pathinfo($name,PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);$dimensions=@getimagesize($path);
        if (!isset(self::TYPES[$extension]) || !is_string($mime) || self::TYPES[$extension]!==strtolower($mime) || !is_array($dimensions)
            || ($dimensions['mime'] ?? '')!==$mime || $dimensions[0]<1 || $dimensions[1]<1
            || $dimensions[0]>12000 || $dimensions[1]>12000 || ((int)$dimensions[0] * (int)$dimensions[1]) > 40000000) {
            throw new RuntimeException('Image content, extension or dimensions are invalid.');
        }
        return new ValidatedMedia($path,$name,$extension==='jpeg'?'jpg':$extension,strtolower($mime),$size,(int)$dimensions[0],(int)$dimensions[1]);
    }
}
