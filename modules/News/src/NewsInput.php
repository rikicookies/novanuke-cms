<?php

declare(strict_types=1);

namespace Modules\News\src;

use NovaNuke\Core\Content\ContentFormat;
use RuntimeException;

final class NewsInput
{

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function article(array $input, bool $canPublish): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $status = (string) ($input['status'] ?? 'draft');
        if ($title === '' || mb_strlen($title) > 200) {
            throw new RuntimeException('Title is required and must not exceed 200 characters.');
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || mb_strlen($slug) > 200) {
            throw new RuntimeException('Slug must use lowercase words separated by hyphens.');
        }
        if (! in_array($status, ['draft', 'scheduled', 'published'], true)) {
            throw new RuntimeException('Invalid publication status.');
        }
        if ($status !== 'draft' && ! $canPublish) {
            throw new RuntimeException('You may save drafts but do not have permission to publish.');
        }
        $publishedAt = $this->date($input['published_at'] ?? null);
        if ($status === 'scheduled' && ($publishedAt === null || $publishedAt <= gmdate('Y-m-d H:i:s'))) {
            throw new RuntimeException('Scheduled news requires a future publication date.');
        }
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = gmdate('Y-m-d H:i:s');
        }
        if ($status === 'published' && $publishedAt > gmdate('Y-m-d H:i:s')) {
            throw new RuntimeException('Use scheduled status for a future publication date.');
        }
        $content = (string) ($input['content'] ?? '');
        if (trim($content) === '') {
            throw new RuntimeException('Article content is required.');
        }
        if (mb_strlen($content) > 1000000) throw new RuntimeException('Article content must not exceed 1,000,000 characters.');
        $contentFormat = ContentFormat::fromInput($input['content_format'] ?? null);
        $summaryFormat = ContentFormat::fromInput($input['summary_format'] ?? null);
        $audience = (string) ($input['audience'] ?? 'public');
        if (! in_array($audience, ['public', 'member', 'vip'], true)) throw new RuntimeException('Invalid news audience.');
        return [
            'title' => $title,
            'slug' => $slug,
            'summary' => $this->limited($input['summary'] ?? null, 1000),
            'summary_format' => $summaryFormat->value,
            'content' => $content,
            'content_format' => $contentFormat->value,
            'featured_image' => $this->image($input['featured_image'] ?? null),
            'status' => $status,
            'audience' => $audience,
            'is_featured' => ($input['is_featured'] ?? null) === '1' ? 1 : 0,
            'comments_enabled' => ($input['comments_enabled'] ?? null) === '1' ? 1 : 0,
            'seo_title' => $this->limited($input['seo_title'] ?? null, 200),
            'seo_description' => $this->limited($input['seo_description'] ?? null, 320),
            'category_id' => $this->id($input['category_id'] ?? null),
            'topic_id' => $this->id($input['topic_id'] ?? null),
            'published_at' => $publishedAt,
            'tags' => $this->tags((string) ($input['tags'] ?? '')),
        ];
    }

    public function taxonomy(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new RuntimeException('Enter a valid name and lowercase slug.');
        }
        return ['name' => $name, 'slug' => $slug, 'description' => $this->limited($input['description'] ?? null, 500)];
    }

    private function id(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid category or topic.');
        return (int) $id;
    }

    private function limited(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (mb_strlen($value) > $max) throw new RuntimeException("Text must not exceed {$max} characters.");
        return $value;
    }

    private function image(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (strlen($value) > 255
            || ! preg_match('#^/uploads/[a-zA-Z0-9/_-]+\.(?:png|jpe?g|gif|webp)$#', $value)
            || str_contains($value, '..')) {
            throw new RuntimeException('Featured image must be a safe path below /uploads/.');
        }
        return $value;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) throw new RuntimeException('Invalid publication date.');
        return $date->format('Y-m-d H:i:s');
    }

    private function tags(string $value): array
    {
        $tags = [];
        foreach (preg_split('/,/', $value) ?: [] as $name) {
            $name = trim($name);
            if ($name === '') continue;
            if (mb_strlen($name) > 80) throw new RuntimeException('Tags must not exceed 80 characters.');
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
            if ($slug !== '') $tags[$slug] = $name;
            if (count($tags) > 20) throw new RuntimeException('Use no more than 20 tags.');
        }
        return $tags;
    }
}
