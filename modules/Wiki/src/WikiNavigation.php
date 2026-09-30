<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

final class WikiNavigation
{
    /**
     * @param list<array<string,mixed>> $pages
     * @return array{pages:list<array<string,mixed>>,namespaces:list<array<string,mixed>>}
     */
    public function sitemap(array $pages): array
    {
        $tree = ['pages' => [], 'namespaces' => []];
        foreach ($pages as $page) {
            $namespace = (string) ($page['namespace'] ?? '');
            $page['path'] = ($namespace === '' ? '' : $namespace . ':') . (string) $page['slug'];
            if ($namespace === '') {
                $tree['pages'][] = $page;
                continue;
            }

            $namespaces =& $tree['namespaces'];
            $path = [];
            foreach (explode(':', $namespace) as $segment) {
                $path[] = $segment;
                if (! isset($namespaces[$segment])) {
                    $namespaces[$segment] = [
                        'path' => implode(':', $path),
                        'label' => $this->label($segment),
                        'pages' => [],
                        'namespaces' => [],
                    ];
                }
                $current =& $namespaces[$segment];
                $namespaces =& $current['namespaces'];
            }
            $current['pages'][] = $page;
            unset($current, $namespaces);
        }

        return [
            'pages' => $this->sortedPages($tree['pages']),
            'namespaces' => $this->normalizedNamespaces($tree['namespaces']),
        ];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @return array{pages:list<array<string,mixed>>,namespaces:list<array{path:string,label:string}>,breadcrumbs:list<array{label:string,url:?string}>}
     */
    public function directory(array $pages, string $namespace): array
    {
        $directPages = [];
        $children = [];
        $prefix = $namespace === '' ? '' : $namespace . ':';

        foreach ($pages as $page) {
            $pageNamespace = (string) ($page['namespace'] ?? '');
            if ($pageNamespace === $namespace) {
                $directPages[] = $page;
                continue;
            }
            if (! str_starts_with($pageNamespace, $prefix)) continue;

            $remaining = substr($pageNamespace, strlen($prefix));
            if ($remaining === '') continue;

            $segment = explode(':', $remaining, 2)[0];
            $path = $namespace === '' ? $segment : $namespace . ':' . $segment;
            $children[$path] = ['path' => $path, 'label' => $this->label($segment)];
        }

        ksort($children, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'pages' => $directPages,
            'namespaces' => array_values($children),
            'breadcrumbs' => $this->namespaceBreadcrumbs($namespace),
        ];
    }

    /** @return list<array{label:string,url:?string}> */
    public function pageBreadcrumbs(string $path, string $title): array
    {
        $segments = explode(':', $path);
        array_pop($segments);
        $breadcrumbs = [['label' => 'Wiki', 'url' => '/wiki']];
        $current = [];

        foreach ($segments as $segment) {
            $current[] = $segment;
            $breadcrumbs[] = [
                'label' => $this->label($segment),
                'url' => '/wiki?namespace=' . rawurlencode(implode(':', $current)),
            ];
        }
        $breadcrumbs[] = ['label' => $title, 'url' => null];

        return $breadcrumbs;
    }

    /** @return list<array{label:string,url:?string}> */
    private function namespaceBreadcrumbs(string $namespace): array
    {
        if ($namespace === '') return [['label' => 'Wiki', 'url' => null]];

        $breadcrumbs = [['label' => 'Wiki', 'url' => '/wiki']];
        $segments = explode(':', $namespace);
        $current = [];
        foreach ($segments as $index => $segment) {
            $current[] = $segment;
            $breadcrumbs[] = [
                'label' => $this->label($segment),
                'url' => $index === array_key_last($segments)
                    ? null
                    : '/wiki?namespace=' . rawurlencode(implode(':', $current)),
            ];
        }

        return $breadcrumbs;
    }

    private function label(string $segment): string
    {
        return ucwords(str_replace('-', ' ', $segment));
    }

    /** @param array<string,array<string,mixed>> $namespaces @return list<array<string,mixed>> */
    private function normalizedNamespaces(array $namespaces): array
    {
        $result = [];
        foreach ($namespaces as $namespace) {
            $namespace['pages'] = $this->sortedPages($namespace['pages']);
            $namespace['namespaces'] = $this->normalizedNamespaces($namespace['namespaces']);
            $result[] = $namespace;
        }
        usort($result, static fn (array $left, array $right): int => strcasecmp((string) $left['label'], (string) $right['label']));
        return $result;
    }

    /** @param list<array<string,mixed>> $pages @return list<array<string,mixed>> */
    private function sortedPages(array $pages): array
    {
        usort($pages, static fn (array $left, array $right): int => strcasecmp((string) $left['title'], (string) $right['title']));
        return $pages;
    }
}
