<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class WikiLinkPresenter
{
    public function __construct(private readonly WikiInput $input)
    {
    }

    /** @param list<string> $existingPaths */
    public function markMissing(string $html, array $existingPaths): string
    {
        if (trim($html) === '') return '';
        $existing = array_fill_keys($existingPaths, true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="wiki-link-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) return $html;

        $root = $document->getElementsByTagName('div')->item(0);
        if (! $root instanceof DOMElement) return $html;
        foreach ($root->getElementsByTagName('a') as $link) {
            $urlPath = parse_url(trim($link->getAttribute('href')), PHP_URL_PATH);
            if (! is_string($urlPath) || ! str_starts_with($urlPath, '/wiki/')) continue;
            try {
                $path = $this->input->path(rawurldecode(substr($urlPath, 6)));
            } catch (RuntimeException) {
                continue;
            }
            if (isset($existing[$path])) continue;

            $classes = preg_split('/\s+/', trim($link->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (! in_array('wiki-link-missing', $classes, true)) $classes[] = 'wiki-link-missing';
            $link->setAttribute('class', implode(' ', $classes));
        }

        $output = '';
        foreach ($root->childNodes as $child) $output .= $document->saveHTML($child);
        return trim($output);
    }
}
