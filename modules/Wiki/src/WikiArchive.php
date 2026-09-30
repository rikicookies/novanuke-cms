<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use RuntimeException;
use ZipArchive;

final class WikiArchive
{
    /** @param list<array<string,mixed>> $pages */
    public function create(array $pages): string
    {
        if (! class_exists(ZipArchive::class)) throw new RuntimeException('The PHP ZIP extension is required to export the Wiki archive.');
        if ($pages === []) throw new RuntimeException('There are no Wiki pages to export.');
        if (count($pages) > 5000) throw new RuntimeException('The Wiki archive cannot contain more than 5,000 pages.');

        $path = tempnam(sys_get_temp_dir(), 'novanuke-wiki-');
        if ($path === false) throw new RuntimeException('The temporary Wiki archive could not be created.');
        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            if (is_file($path)) unlink($path);
            throw new RuntimeException('The Wiki archive could not be opened.');
        }

        foreach ($pages as $page) {
            $entry = $this->entryPath((string) ($page['namespace'] ?? ''), (string) ($page['slug'] ?? ''));
            if (! $archive->addFromString($entry, (string) ($page['content'] ?? ''))) {
                $archive->close();
                if (is_file($path)) unlink($path);
                throw new RuntimeException('A Wiki page could not be added to the archive.');
            }
        }
        if (! $archive->close()) {
            if (is_file($path)) unlink($path);
            throw new RuntimeException('The Wiki archive could not be finalized.');
        }
        return $path;
    }

    public function entryPath(string $namespace, string $slug): string
    {
        $wikiPath = ($namespace === '' ? '' : $namespace . ':') . $slug;
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $wikiPath)) {
            throw new RuntimeException('A Wiki page has an invalid archive path.');
        }
        return str_replace(':', '/', $wikiPath) . '.md';
    }
}
