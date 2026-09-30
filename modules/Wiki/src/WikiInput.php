<?php

declare(strict_types=1);

namespace Modules\Wiki\src;

use RuntimeException;

final class WikiInput
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function page(array $input, bool $canPublish): array
    {
        $path = $this->path($input['path'] ?? '');
        $parts = explode(':', $path);
        $slug = (string) array_pop($parts);
        $title = trim((string) ($input['title'] ?? ''));
        $content = (string) ($input['content'] ?? '');
        $status = (string) ($input['status'] ?? 'draft');
        $audience = (string) ($input['audience'] ?? 'public');

        if ($title === '' || mb_strlen($title) > 200) throw new RuntimeException('Title is required and must not exceed 200 characters.');
        if (trim($content) === '' || mb_strlen($content) > 1000000) throw new RuntimeException('Markdown content is required and must not exceed 1,000,000 characters.');
        if (! in_array($status, ['draft', 'published'], true)) throw new RuntimeException('Invalid wiki status.');
        if ($status === 'published' && ! $canPublish) throw new RuntimeException('You may save drafts but do not have permission to publish.');
        if (! in_array($audience, ['public', 'member', 'vip'], true)) throw new RuntimeException('Invalid wiki audience.');

        return [
            'namespace' => implode(':', $parts),
            'slug' => $slug,
            'path' => $path,
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'audience' => $audience,
            'comments_enabled' => ($input['comments_enabled'] ?? null) === '1' ? 1 : 0,
        ];
    }

    public function path(mixed $value): string
    {
        $path = strtolower(trim((string) $value));
        if (strlen($path) > 240 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $path)) {
            throw new RuntimeException('Wiki paths use lowercase words separated by hyphens and namespaces separated by colons.');
        }
        foreach (explode(':', $path) as $part) {
            if (strlen($part) > 120) throw new RuntimeException('Each wiki path segment must not exceed 120 characters.');
        }
        $namespace = substr($path, 0, (int) strrpos($path, ':'));
        if (str_contains($path, ':') && strlen($namespace) > 190) {
            throw new RuntimeException('The wiki namespace must not exceed 190 characters.');
        }
        return $path;
    }

    public function namespace(mixed $value): string
    {
        if (! is_string($value)) throw new RuntimeException('Invalid wiki namespace.');
        $namespace = strtolower(trim((string) $value));
        if ($namespace === '') return '';
        if (strlen($namespace) > 190 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)*$/', $namespace)) {
            throw new RuntimeException('Invalid wiki namespace.');
        }
        foreach (explode(':', $namespace) as $part) {
            if (strlen($part) > 120) throw new RuntimeException('Each wiki namespace segment must not exceed 120 characters.');
        }
        return $namespace;
    }

    public function searchTerm(mixed $value): string
    {
        if (! is_string($value)) throw new RuntimeException('Invalid Wiki search term.');
        $term = trim($value);
        if (preg_match('//u', $term) !== 1 || mb_strlen($term) < 2 || mb_strlen($term) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $term)) {
            throw new RuntimeException('Wiki searches must contain between 2 and 100 characters.');
        }
        return $term;
    }
}
