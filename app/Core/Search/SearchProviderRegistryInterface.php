<?php

declare(strict_types=1);

namespace NovaNuke\Core\Search;

interface SearchProviderRegistryInterface
{
    public function add(SearchProviderInterface $provider): void;

    /** @return array<string,SearchProviderInterface> */
    public function all(): array;

    public function get(string $type): ?SearchProviderInterface;
}
