<?php

declare(strict_types=1);

namespace NovaNuke\Core\Search;

final readonly class SearchResultItem
{
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public string $excerpt,
        public string $publishedAt,
    ) {
        if (! preg_match('/^[a-z][a-z0-9-]{0,49}$/', $type)
            || ! preg_match('#^/(?!/)[^\x00-\x20\x7F]*$#', $url)) {
            throw new \InvalidArgumentException('Invalid search result item.');
        }
    }
}
