<?php

declare(strict_types=1);

namespace NovaNuke\Core\Search;

final readonly class SearchProvidersRegistering
{
    public function __construct(public SearchProviderRegistryInterface $registry)
    {
    }
}
