<?php

declare(strict_types=1);

namespace NovaNuke\Core\Search;

interface SearchProviderInterface
{
    public function type(): string;

    public function label(): string;

    public function search(SearchQuery $query): SearchProviderResult;
}
