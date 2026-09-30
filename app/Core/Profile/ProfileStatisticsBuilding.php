<?php

declare(strict_types=1);

namespace NovaNuke\Core\Profile;

class ProfileStatisticsBuilding
{
    /** @var list<array{label:string,value:int,url:?string}> */
    private array $statistics = [];

    public function __construct(public readonly int $profileId)
    {
    }

    public function add(string $label, int $value, ?string $url = null): void
    {
        if ($label !== '' && $value >= 0 && ($url === null || (str_starts_with($url, '/') && ! str_starts_with($url, '//')))) {
            $this->statistics[] = compact('label', 'value', 'url');
        }
    }

    /** @return list<array{label:string,value:int,url:?string}> */
    public function statistics(): array
    {
        return $this->statistics;
    }
}
