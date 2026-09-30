<?php

declare(strict_types=1);

namespace NovaNuke\Core\Profile;

class ProfileActionsBuilding
{
    /** @var list<array{label:string,url:string,method:string}> */
    private array $actions = [];

    public function __construct(
        public readonly int $profileId,
        public readonly string $profileUsername,
        public readonly int $viewerId,
    ) {
    }

    public function add(string $label, string $url, string $method = 'post'): void
    {
        if ($label !== '' && str_starts_with($url, '/') && ! str_starts_with($url, '//') && in_array($method, ['get', 'post'], true)) {
            $this->actions[] = compact('label', 'url', 'method');
        }
    }

    /** @return list<array{label:string,url:string,method:string}> */
    public function actions(): array
    {
        return $this->actions;
    }
}
