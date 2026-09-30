<?php

declare(strict_types=1);

namespace NovaNuke\Core\Search;

final readonly class SearchProviderResult
{
    /** @param list<SearchResultItem> $items */
    public function __construct(public array $items, public int $total)
    {
        if ($total < count($items)) throw new \InvalidArgumentException('Provider total cannot be smaller than its items.');
        foreach ($items as $item) {
            if (! $item instanceof SearchResultItem) throw new \InvalidArgumentException('Providers must return SearchResultItem objects.');
        }
    }
}
