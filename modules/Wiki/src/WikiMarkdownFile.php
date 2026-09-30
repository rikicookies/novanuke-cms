<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use finfo;
use RuntimeException;

final class WikiMarkdownFile
{
    private const MAX_BYTES = 1000000;
    private const MIME_TYPES = ['text/plain', 'text/markdown', 'text/x-markdown'];

    /** @param array<string,mixed>|null $file @return array<string,string> */
    public function import(?array $file): array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Select a Markdown .md file.');
        }
        if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The Markdown upload did not complete successfully.');
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        $size = (int) ($file['size'] ?? -1);
        if ($temporaryPath === '' || ! is_file($temporaryPath) || ! is_readable($temporaryPath)
            || $originalName === '' || $size < 1 || $size > self::MAX_BYTES || filesize($temporaryPath) !== $size) {
            throw new RuntimeException('Markdown files must be readable, non-empty and no larger than 1 MB.');
        }
        if (strlen($originalName) > 255 || preg_match('/[\x00-\x1F\x7F]/', $originalName)) {
            throw new RuntimeException('The Markdown filename is invalid.');
        }
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'md') {
            throw new RuntimeException('Only files with the .md extension may be imported.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (! is_string($mime) || ! in_array(strtolower($mime), self::MIME_TYPES, true)) {
            throw new RuntimeException('The uploaded file is not recognized as plain Markdown text.');
        }

        $content = file_get_contents($temporaryPath);
        if (! is_string($content)) throw new RuntimeException('The Markdown file could not be read.');
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        if (! mb_check_encoding($content, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
            throw new RuntimeException('Markdown files must contain valid UTF-8 text without binary control characters.');
        }
        if (trim($content) === '') throw new RuntimeException('The Markdown file contains no content.');

        $stem = (string) pathinfo($originalName, PATHINFO_FILENAME);
        $path = strtolower($stem);
        $path = trim((string) preg_replace('/[^a-z0-9]+/', '-', $path), '-');
        $path = rtrim(substr($path, 0, 120), '-');
        if ($path === '') $path = 'imported-page';
        $title = $this->title($content, $stem);

        return ['path' => $path, 'title' => $title, 'content' => $content, 'status' => 'draft', 'audience' => 'public'];
    }

    public function filename(string $wikiPath): string
    {
        $safePath = strtolower((string) preg_replace('/[^a-z0-9:-]+/i', '-', $wikiPath));
        $safePath = trim(str_replace(':', '-', $safePath), '-');
        return ($safePath !== '' ? $safePath : 'wiki-page') . '.md';
    }

    private function title(string $content, string $fallback): string
    {
        if (preg_match('/^#\s+(.+?)\s*#*\s*$/m', $content, $match)) {
            $title = trim(strip_tags((string) $match[1]));
            if ($title !== '' && mb_strlen($title) <= 200) return $title;
        }
        $title = trim((string) preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $fallback)));
        $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
        return $title !== '' && mb_strlen($title) <= 200 ? $title : 'Imported page';
    }
}
