<?php

declare(strict_types=1);

namespace Modules\Pages\src;

use DateTimeImmutable;
use DateTimeZone;
use NovaNuke\Core\Content\ContentFormat;
use RuntimeException;

final class PageInput
{

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function page(array $input, bool $canPublish): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $status = (string) ($input['status'] ?? 'draft');
        if ($title === '' || mb_strlen($title) > 200) throw new RuntimeException('Title is required and must not exceed 200 characters.');
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || mb_strlen($slug) > 200) throw new RuntimeException('Slug must use lowercase words separated by hyphens.');
        if (! in_array($status, ['draft', 'scheduled', 'published'], true)) throw new RuntimeException('Invalid publication status.');
        if ($status !== 'draft' && ! $canPublish) throw new RuntimeException('You may save drafts but do not have permission to publish.');
        $publishedAt = $this->date($input['published_at'] ?? null);
        if ($status === 'scheduled' && ($publishedAt === null || $publishedAt <= gmdate('Y-m-d H:i:s'))) throw new RuntimeException('Scheduled pages require a future publication date.');
        if ($status === 'published' && $publishedAt === null) $publishedAt = gmdate('Y-m-d H:i:s');
        if ($status === 'published' && $publishedAt > gmdate('Y-m-d H:i:s')) throw new RuntimeException('Use scheduled status for a future publication date.');
        $content = (string) ($input['content'] ?? '');
        if (trim($content) === '') throw new RuntimeException('Page content is required.');
        if (mb_strlen($content) > 1000000) throw new RuntimeException('Page content must not exceed 1,000,000 characters.');
        $contentFormat = ContentFormat::fromInput($input['content_format'] ?? null);
        $template = (string) ($input['template'] ?? 'default');
        if (! in_array($template, ['default', 'landing'], true)) throw new RuntimeException('Invalid page template.');
        $access = (string) ($input['access_type'] ?? 'public');
        if (! in_array($access, ['public', 'members', 'vip', 'roles'], true)) throw new RuntimeException('Invalid page access type.');
        $roles = array_values(array_unique(array_filter(array_map('intval', (array) ($input['role_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        if ($access === 'roles' && $roles === []) throw new RuntimeException('Select at least one role for role-restricted pages.');
        return [
            'title' => $title, 'slug' => $slug, 'content' => $content, 'content_format' => $contentFormat->value,
            'image_path' => $this->image($input['image_path'] ?? null), 'status' => $status,
            'template' => $template, 'access_type' => $access,
            'comments_enabled' => ($input['comments_enabled'] ?? null) === '1' ? 1 : 0,
            'show_in_directory' => ($input['show_in_directory'] ?? null) === '1' ? 1 : 0,
            'menu_title' => $this->limited($input['menu_title'] ?? null, 120),
            'seo_title' => $this->limited($input['seo_title'] ?? null, 200),
            'seo_description' => $this->limited($input['seo_description'] ?? null, 320),
            'parent_id' => $this->id($input['parent_id'] ?? null), 'published_at' => $publishedAt,
            'role_ids' => $access === 'roles' ? $roles : [],
        ];
    }

    private function id(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid parent page.');
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
        if (strlen($value) > 255 || ! preg_match('#^/uploads/[a-zA-Z0-9/_-]+\.(?:png|jpe?g|gif|webp)$#', $value) || str_contains($value, '..')) {
            throw new RuntimeException('Page image must be a safe path below /uploads/.');
        }
        return $value;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) throw new RuntimeException('Invalid publication date.');
        return $date->format('Y-m-d H:i:s');
    }
}
