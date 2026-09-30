<?php

declare(strict_types=1);

namespace Modules\Search\src;

use NovaNuke\Core\Search\SearchProviderInterface;
use NovaNuke\Core\Search\SearchProviderRegistryInterface;
use RuntimeException;

final class SearchProviderRegistry implements SearchProviderRegistryInterface
{
    /** @var array<string,SearchProviderInterface> */
    private array $providers = [];

    public function add(SearchProviderInterface $provider): void
    {
        $type = $provider->type();
        if (! preg_match('/^[a-z][a-z0-9-]{0,49}$/', $type)) throw new RuntimeException('Invalid search provider type.');
        if (isset($this->providers[$type])) throw new RuntimeException("Duplicate search provider: {$type}");
        $this->providers[$type] = $provider;
    }

    /** @return array<string,SearchProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }

    public function get(string $type): ?SearchProviderInterface
    {
        return $this->providers[$type] ?? null;
    }
}
