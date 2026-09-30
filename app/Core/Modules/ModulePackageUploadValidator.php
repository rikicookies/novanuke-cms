<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use Closure;
use RuntimeException;

final class ModulePackageUploadValidator
{
    private const MAX_BYTES = 52_428_800;

    /** @var Closure(string):bool */
    private readonly Closure $isUploadedFile;

    /** @param (Closure(string):bool)|null $isUploadedFile */
    public function __construct(?Closure $isUploadedFile = null)
    {
        $this->isUploadedFile = $isUploadedFile ?? static fn (string $path): bool => is_uploaded_file($path);
    }

    /** @param array<string,mixed>|null $file */
    public function validate(?array $file): string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Select a NovaNuke module ZIP package.');
        }
        if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The module package upload did not complete successfully.');
        }
        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        if ($name === '' || ! str_ends_with(strtolower($name), '.zip')) {
            throw new RuntimeException('Module packages must use the .zip extension.');
        }
        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || ! ($this->isUploadedFile)($path) || ! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new RuntimeException('The uploaded module package is not a valid temporary file.');
        }
        $actualSize = filesize($path);
        $reportedSize = filter_var($file['size'] ?? null, FILTER_VALIDATE_INT);
        if ($actualSize === false || $actualSize <= 0 || $actualSize > self::MAX_BYTES
            || $reportedSize === false || $reportedSize !== $actualSize) {
            throw new RuntimeException('Module package must be a non-empty ZIP no larger than 50 MB.');
        }
        $handle = fopen($path, 'rb');
        $signature = is_resource($handle) ? fread($handle, 4) : false;
        if (is_resource($handle)) fclose($handle);
        if (! is_string($signature) || ! in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            throw new RuntimeException('The uploaded file is not a ZIP package.');
        }
        return $path;
    }
}
