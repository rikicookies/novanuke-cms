<?php

declare(strict_types=1);

namespace Modules\News\src;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class RssFeedBuilder
{
    /** @param list<array<string,mixed>> $articles */
    public function build(string $siteName, string $siteDescription, string $baseUrl, string $language, array $articles): string
    {
        $baseUrl = $this->baseUrl($baseUrl);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $rss = $document->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $document->appendChild($rss);
        $channel = $document->createElement('channel'); $rss->appendChild($channel);
        $this->text($document, $channel, 'title', trim($siteName) !== '' ? trim($siteName) . ' News' : 'NovaNuke News');
        $this->text($document, $channel, 'link', $baseUrl . '/news');
        $this->text($document, $channel, 'description', trim($siteDescription) !== '' ? trim($siteDescription) : 'Latest published news.');
        $this->text($document, $channel, 'language', $this->language($language));
        $this->text($document, $channel, 'lastBuildDate', $this->date($articles[0]['published_at'] ?? 'now'));
        $atom = $document->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
        $atom->setAttribute('href', $baseUrl . '/news/rss.xml'); $atom->setAttribute('rel', 'self'); $atom->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atom);
        foreach ($articles as $article) {
            $item = $document->createElement('item'); $channel->appendChild($item);
            $url = $baseUrl . '/news/' . rawurlencode((string) $article['slug']);
            $this->text($document, $item, 'title', (string) $article['title']);
            $this->text($document, $item, 'link', $url);
            $description = trim((string) ($article['summary'] ?? ''));
            if ($description === '') $description = mb_substr(trim(strip_tags((string) ($article['content'] ?? ''))), 0, 500);
            $this->text($document, $item, 'description', $description);
            $guid = $this->text($document, $item, 'guid', $url); $guid->setAttribute('isPermaLink', 'true');
            $this->text($document, $item, 'pubDate', $this->date($article['published_at'] ?? null));
            if (trim((string) ($article['category_name'] ?? '')) !== '') $this->text($document, $item, 'category', (string) $article['category_name']);
            if (trim((string) ($article['username'] ?? '')) !== '') {
                $creator = $document->createElementNS('http://purl.org/dc/elements/1.1/', 'dc:creator');
                $creator->appendChild($document->createTextNode((string) $article['username'])); $item->appendChild($creator);
            }
        }
        $xml = $document->saveXML();
        if (! is_string($xml)) throw new RuntimeException('RSS feed could not be generated.');
        return $xml;
    }

    private function text(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $document->createElement($name); $element->appendChild($document->createTextNode($value)); $parent->appendChild($element);
        return $element;
    }

    private function baseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)
            || parse_url($url, PHP_URL_HOST) === null || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('The configured site URL is invalid for RSS.');
        }
        return $url;
    }

    private function language(string $language): string
    {
        $language = str_replace('_', '-', strtolower(trim($language)));
        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $language) ? $language : 'en';
    }

    private function date(mixed $value): string
    {
        try { return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format(DATE_RSS); }
        catch (\Throwable $error) { throw new RuntimeException('RSS contains an invalid publication date.', 0, $error); }
    }
}
