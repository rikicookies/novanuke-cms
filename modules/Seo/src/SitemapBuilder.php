<?php

declare(strict_types=1);

namespace Modules\Seo\src;

use NovaNuke\Core\Sitemap\SitemapCollecting;
use DOMDocument;
use InvalidArgumentException;

final class SitemapBuilder
{
    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function build(string $baseUrl, SitemapCollecting $collection): string
    {
        $baseUrl = $this->normalizeBaseUrl($baseUrl);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::XMLNS, 'urlset');
        $document->appendChild($root);
        foreach ($collection->urls() as $item) {
            $url = $document->createElementNS(self::XMLNS, 'url');
            $this->text($document, $url, 'loc', $baseUrl . $item['path']);
            if ($item['last_modified'] !== null) $this->text($document, $url, 'lastmod', gmdate('c', (int) strtotime($item['last_modified'])));
            if ($item['change_frequency'] !== null) $this->text($document, $url, 'changefreq', $item['change_frequency']);
            if ($item['priority'] !== null) $this->text($document, $url, 'priority', number_format($item['priority'], 1, '.', ''));
            $root->appendChild($url);
        }
        return (string) $document->saveXML();
    }

    public function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http','https'], true)
            || parse_url($baseUrl, PHP_URL_USER) !== null || parse_url($baseUrl, PHP_URL_PASS) !== null
            || parse_url($baseUrl, PHP_URL_QUERY) !== null || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
            || preg_match('/[\x00-\x20\x7F]/', $baseUrl)) throw new InvalidArgumentException('Site URL must be a safe HTTP or HTTPS URL.');
        return $baseUrl;
    }

    private function text(DOMDocument $document, \DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElementNS(self::XMLNS, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
