<?php

declare(strict_types=1);

namespace Modules\Search\src;

use NovaNuke\Core\Search\SearchQuery;
use NovaNuke\Core\Search\SearchResultItem;
use RuntimeException;

final class SearchService
{
    public function __construct(
        private readonly SearchProviderRegistry $providers,
        private readonly SafeHighlighter $highlighter,
        private readonly int $perPage = 10,
    ) {
    }

    /** @return array<string,mixed> */
    public function search(string $term, string $type, int $page, ?int $userId): array
    {
        $term = trim($term);
        if (! mb_check_encoding($term, 'UTF-8') || mb_strlen($term) < 2 || mb_strlen($term) > 100) {
            throw new RuntimeException('Search terms must contain between 2 and 100 characters.');
        }
        $page = max(1, min(100, $page));
        $selected = $type === '' ? $this->providers->all() : array_filter([$type => $this->providers->get($type)]);
        if ($type !== '' && $selected === []) throw new RuntimeException('Unknown search content type.');

        $limit = min($page * $this->perPage, 200);
        $items = []; $total = 0;
        foreach ($selected as $provider) {
            $result = $provider->search(new SearchQuery($term, $limit, $userId));
            $total += $result->total;
            array_push($items, ...$result->items);
        }
        usort($items, static fn (SearchResultItem $left, SearchResultItem $right): int =>
            strcmp($right->publishedAt, $left->publishedAt) ?: strcasecmp($left->title, $right->title));
        $retrievablePages = max(1, count($selected) * intdiv(200, $this->perPage));
        $pages = max(1, min($retrievablePages, (int) ceil($total / $this->perPage)));
        $page = min($page, $pages);
        $items = array_slice($items, ($page - 1) * $this->perPage, $this->perPage);

        $rendered = array_map(fn (SearchResultItem $item): array => [
            'type' => $item->type, 'title' => $item->title, 'url' => $item->url,
            'title_html' => $this->highlighter->highlight($item->title, $term),
            'excerpt_html' => $this->highlighter->highlight($this->excerpt($item->excerpt), $term),
            'published_at' => $item->publishedAt,
        ], $items);
        return [
            'items' => $rendered, 'total' => $total, 'page' => $page,
            'pages' => $pages,
        ];
    }

    private function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return mb_strlen($text) > 240 ? mb_substr($text, 0, 237) . '...' : $text;
    }
}
