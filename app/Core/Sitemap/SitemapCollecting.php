<?php

declare(strict_types=1);

namespace NovaNuke\Core\Sitemap;

use InvalidArgumentException;

final class SitemapCollecting
{
    /** @var array<string,array{path:string,last_modified:?string,change_frequency:?string,priority:?float}> */
    private array $urls = [];

    public function add(string $path, ?string $lastModified = null, ?string $changeFrequency = null, ?float $priority = null): void
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '?')
            || preg_match('/[\x00-\x20\x7F]/', $path)) throw new InvalidArgumentException('Sitemap path must be a clean internal path.');
        if ($lastModified !== null && strtotime($lastModified) === false) throw new InvalidArgumentException('Sitemap modification date is invalid.');
        $frequencies = ['always','hourly','daily','weekly','monthly','yearly','never'];
        if ($changeFrequency !== null && ! in_array($changeFrequency, $frequencies, true)) throw new InvalidArgumentException('Sitemap frequency is invalid.');
        if ($priority !== null && ($priority < 0 || $priority > 1)) throw new InvalidArgumentException('Sitemap priority is invalid.');
        if (count($this->urls) >= 50000 && ! isset($this->urls[$path])) throw new InvalidArgumentException('A sitemap cannot exceed 50,000 URLs.');
        $this->urls[$path] = ['path'=>$path,'last_modified'=>$lastModified,'change_frequency'=>$changeFrequency,'priority'=>$priority];
    }

    /** @return array<int,array{path:string,last_modified:?string,change_frequency:?string,priority:?float}> */
    public function urls(): array
    {
        ksort($this->urls, SORT_STRING);
        return array_values($this->urls);
    }
}
