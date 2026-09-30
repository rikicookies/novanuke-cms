<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use RuntimeException;

final class WikiFolderImport
{
    private const MAX_FILES = 100;
    private const MAX_TOTAL_BYTES = 20000000;

    public function __construct(
        private readonly WikiMarkdownFile $markdown,
        private readonly WikiInput $input,
    ) {
    }

    /**
     * @param array<string,mixed>|null $files
     * @return array{drafts:list<array<string,mixed>>,ignored:int}
     */
    public function drafts(?array $files, mixed $submittedPaths = [], mixed $expectedCount = null): array
    {
        if ($files === null || ! isset($files['name'], $files['tmp_name'], $files['error'], $files['size'])
            || ! is_array($files['name']) || ! is_array($files['tmp_name'])
            || ! is_array($files['error']) || ! is_array($files['size'])) {
            throw new RuntimeException('Select a folder containing Markdown files.');
        }
        $count = count($files['name']);
        if ($count < 1 || $count > self::MAX_FILES
            || count($files['tmp_name']) !== $count || count($files['error']) !== $count || count($files['size']) !== $count) {
            throw new RuntimeException('A Wiki folder import may contain between 1 and 100 files.');
        }
        if ($expectedCount !== null && $expectedCount !== '' && $expectedCount !== '0') {
            $expected = filter_var($expectedCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_FILES]]);
            if ($expected === false || $expected !== $count) {
                throw new RuntimeException('PHP accepted only part of the selected folder. Increase max_file_uploads or import a smaller folder.');
            }
        }

        $browserPaths = isset($files['full_path']) && is_array($files['full_path']) ? array_values($files['full_path']) : [];
        $submittedPaths = is_array($submittedPaths) ? array_values($submittedPaths) : [];
        $drafts = [];
        $seen = [];
        $ignored = 0;
        $totalBytes = 0;

        for ($index = 0; $index < $count; $index++) {
            $name = $files['name'][$index] ?? null;
            $temporaryPath = $files['tmp_name'][$index] ?? null;
            $error = $files['error'][$index] ?? null;
            $size = $files['size'][$index] ?? null;
            if (! is_string($name) || ! is_string($temporaryPath) || ! is_int($error) || ! is_int($size) || $size < 0) {
                throw new RuntimeException('The Wiki folder upload is malformed.');
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) throw new RuntimeException('The Wiki folder import cannot exceed 20 MB.');
            if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'md') {
                $ignored++;
                continue;
            }

            $relativePath = $submittedPaths[$index] ?? $browserPaths[$index] ?? $name;
            if (! is_string($relativePath)) throw new RuntimeException('A Wiki folder path is invalid.');
            $wikiPath = $this->wikiPath($relativePath);
            if (isset($seen[$wikiPath])) throw new RuntimeException('Multiple Markdown files resolve to the same Wiki path: ' . $wikiPath);

            $draft = $this->markdown->import([
                'name' => $name,
                'tmp_name' => $temporaryPath,
                'error' => $error,
                'size' => $size,
            ]);
            $draft['path'] = $this->input->path($wikiPath);
            $drafts[] = $draft;
            $seen[$wikiPath] = true;
        }

        if ($drafts === []) throw new RuntimeException('The selected folder contains no valid Markdown files.');
        return ['drafts' => $drafts, 'ignored' => $ignored];
    }

    public function wikiPath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('/[\x00-\x1F\x7F]/', $relativePath)) {
            throw new RuntimeException('A Wiki folder path is invalid.');
        }
        $parts = explode('/', $relativePath);
        if (count($parts) > 20 || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new RuntimeException('Wiki folders cannot be empty, nested more than 20 levels or contain traversal segments.');
        }
        $filename = array_pop($parts);
        if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== 'md') {
            throw new RuntimeException('Only Markdown files can become Wiki pages.');
        }
        $parts[] = (string) pathinfo($filename, PATHINFO_FILENAME);
        $segments = array_map(fn (string $segment): string => $this->segment($segment), $parts);
        return implode(':', $segments);
    }

    private function segment(string $value): string
    {
        $segment = strtolower(trim($value));
        $segment = trim((string) preg_replace('/[^a-z0-9]+/', '-', $segment), '-');
        if ($segment === '' || strlen($segment) > 120) throw new RuntimeException('A Wiki folder or filename cannot form a valid namespace segment.');
        return $segment;
    }
}
