<?php

declare(strict_types=1);

namespace Modules\Downloads\src;

use DateTimeImmutable;
use DateTimeZone;
use NovaNuke\Core\Content\ContentFormat;
use RuntimeException;

final class DownloadInput
{

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function download(array $input, bool $canPublish): array
    {
        $name = trim((string) ($input['name'] ?? '')); $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        if ($name === '' || mb_strlen($name) > 200) throw new RuntimeException('Name is required and must not exceed 200 characters.');
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || mb_strlen($slug) > 200) throw new RuntimeException('Slug must use lowercase words separated by hyphens.');
        $description = (string) ($input['description'] ?? '');
        if (trim($description) === '') throw new RuntimeException('Description is required.');
        if (mb_strlen($description) > 100000) throw new RuntimeException('Description must not exceed 100,000 characters.');
        $descriptionFormat = ContentFormat::fromInput($input['description_format'] ?? null);
        $requirementsFormat = ContentFormat::fromInput($input['requirements_format'] ?? null);
        $source = (string) ($input['source_type'] ?? 'local');
        if (! in_array($source, ['local', 'external'], true)) throw new RuntimeException('Invalid download source type.');
        $status = (string) ($input['status'] ?? 'draft');
        if (! in_array($status, ['draft', 'scheduled', 'published'], true)) throw new RuntimeException('Invalid publication status.');
        if ($status !== 'draft' && ! $canPublish) throw new RuntimeException('You may save drafts but do not have permission to publish.');
        $published = $this->date($input['published_at'] ?? null);
        if ($status === 'scheduled' && ($published === null || $published <= gmdate('Y-m-d H:i:s'))) throw new RuntimeException('Scheduled downloads require a future publication date.');
        if ($status === 'published' && $published === null) $published = gmdate('Y-m-d H:i:s');
        if ($status === 'published' && $published > gmdate('Y-m-d H:i:s')) throw new RuntimeException('Use scheduled status for a future publication date.');
        $access = (string) ($input['access_type'] ?? 'public');
        if (! in_array($access, ['public', 'members', 'vip', 'roles'], true)) throw new RuntimeException('Invalid access type.');
        $roles = array_values(array_unique(array_filter(array_map('intval', (array) ($input['role_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        if ($access === 'roles' && $roles === []) throw new RuntimeException('Select at least one role for restricted downloads.');
        return [
            'name' => $name, 'slug' => $slug, 'description' => $description, 'description_format' => $descriptionFormat->value,
            'version' => $this->limited($input['version'] ?? null, 50), 'author_name' => $this->limited($input['author_name'] ?? null, 150),
            'source_type' => $source, 'external_url' => $source === 'external' ? $this->url($input['external_url'] ?? null) : null,
            'image_path' => $this->image($input['image_path'] ?? null), 'requirements' => $this->limited($input['requirements'] ?? null, 2000),
            'requirements_format' => $requirementsFormat->value,
            'license_name' => $this->limited($input['license_name'] ?? null, 150), 'status' => $status,
            'access_type' => $access, 'is_featured' => ($input['is_featured'] ?? null) === '1' ? 1 : 0,
            'category_id' => $this->id($input['category_id'] ?? null), 'published_at' => $published,
            'role_ids' => $access === 'roles' ? $roles : [],
        ];
    }

    public function category(array $input): array
    {
        $name = trim((string) ($input['name'] ?? '')); $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) throw new RuntimeException('Enter a valid category name and slug.');
        return ['name' => $name, 'slug' => $slug, 'description' => $this->limited($input['description'] ?? null, 500), 'parent_id' => $this->id($input['parent_id'] ?? null)];
    }

    private function id(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('Invalid identifier.');
        return (int) $id;
    }

    private function limited(mixed $value, int $max): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        if (mb_strlen($value) > $max) throw new RuntimeException("Text must not exceed {$max} characters.");
        return $value;
    }

    private function image(mixed $value): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        if (strlen($value) > 255 || ! preg_match('#^/uploads/[a-zA-Z0-9/_-]+\.(?:png|jpe?g|gif|webp)$#', $value) || str_contains($value, '..')) throw new RuntimeException('Image must be a safe path below /uploads/.');
        return $value;
    }

    private function url(mixed $value): string
    {
        $url = trim((string) $value); $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || preg_match('/[\x00-\x1F\x7F]/', $url)) throw new RuntimeException('External URL must use safe HTTP or HTTPS.');
        return $url;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) throw new RuntimeException('Invalid publication date.');
        return $date->format('Y-m-d H:i:s');
    }
}
