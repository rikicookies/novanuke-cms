<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use PDO;
use RuntimeException;

final class ProfileRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array<string,mixed>|null */
    public function byUserId(int $userId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT u.id,u.username,u.email,u.created_at,p.display_name,p.avatar_path,p.bio,p.bio_format,p.website,p.location,p.locale,p.timezone,p.preferences '
            . 'FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.id=:id AND u.deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        return $this->normalize($statement->fetch());
    }

    /** @return array<string,mixed>|null */
    public function byUsername(string $username): ?array
    {
        $statement = $this->database->prepare(
            "SELECT u.id,u.username,u.created_at,p.display_name,p.avatar_path,p.bio,p.bio_format,p.website,p.location,p.locale,p.timezone,p.preferences "
            . "FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.username=:username AND u.status='active' AND u.deleted_at IS NULL LIMIT 1"
        );
        $statement->execute(['username' => $username]);
        return $this->normalize($statement->fetch());
    }

    /** @return array{items:array<int,array<string,mixed>>,page:int,pages:int,total:int} */
    public function directory(int $page, bool $includeMembers, int $perPage = 24): array
    {
        $visibility = $includeMembers ? '' : " AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.preferences,'$.profile_visibility')),'public')='public'";
        $where = "u.status='active' AND u.deleted_at IS NULL" . $visibility;
        $count = $this->database->query("SELECT COUNT(*) FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE {$where}");
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $statement = $this->database->prepare(
            "SELECT u.id,u.username,u.created_at,p.display_name,p.avatar_path,p.preferences FROM users u "
            . "LEFT JOIN user_profiles p ON p.user_id=u.id WHERE {$where} "
            . 'ORDER BY u.created_at DESC,u.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map(fn (array $record): array => $this->normalize($record) ?? [], $statement->fetchAll());
        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /** @param array<string,mixed> $data */
    public function update(int $userId, array $data): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO user_profiles (user_id,display_name,bio,bio_format,website,location,locale,timezone,preferences,created_at,updated_at) '
            . 'SELECT id,:display_name,:bio,:bio_format,:website,:location,:locale,:timezone,:preferences,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM users '
            . 'WHERE id=:user_id AND deleted_at IS NULL ON DUPLICATE KEY UPDATE '
            . 'display_name=VALUES(display_name),bio=VALUES(bio),bio_format=VALUES(bio_format),website=VALUES(website),location=VALUES(location),locale=VALUES(locale),timezone=VALUES(timezone),'
            . 'preferences=VALUES(preferences),updated_at=UTC_TIMESTAMP()'
        );
        $statement->execute([
            'display_name' => $data['display_name'], 'bio' => $data['bio'] === '' ? null : $data['bio'],
            'bio_format' => $data['bio_format'],
            'website' => $data['website'] === '' ? null : $data['website'], 'location' => $data['location'] === '' ? null : $data['location'],
            'locale' => $data['locale'], 'timezone' => $data['timezone'],
            'preferences' => json_encode($data['preferences'], JSON_THROW_ON_ERROR), 'user_id' => $userId,
        ]);
        if ($statement->rowCount() > 2) throw new RuntimeException('Profile update affected an unexpected number of records.');
    }

    public function setLocale(int $userId, string $locale): void
    {
        $statement = $this->database->prepare(
            "INSERT INTO user_profiles (user_id,display_name,locale,timezone,created_at,updated_at) "
            . "SELECT id,username,:locale,'UTC',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM users "
            . "WHERE id=:id AND deleted_at IS NULL ON DUPLICATE KEY UPDATE locale=VALUES(locale),updated_at=UTC_TIMESTAMP()"
        );
        $statement->execute(['locale' => $locale, 'id' => $userId]);
    }

    public function setAvatar(int $userId, ?string $path): void
    {
        $statement = $this->database->prepare(
            "INSERT INTO user_profiles (user_id,display_name,avatar_path,locale,timezone,created_at,updated_at) "
            . "SELECT id,username,:path,'en','UTC',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM users "
            . "WHERE id=:id AND deleted_at IS NULL ON DUPLICATE KEY UPDATE avatar_path=VALUES(avatar_path),updated_at=UTC_TIMESTAMP()"
        );
        $statement->execute(['path' => $path, 'id' => $userId]);
    }

    /** @return array<string,mixed>|null */
    private function normalize(mixed $record): ?array
    {
        if (! is_array($record)) return null;
        $record['display_name'] = is_string($record['display_name'] ?? null) && trim($record['display_name']) !== ''
            ? $record['display_name'] : (string) ($record['username'] ?? 'Member');
        $record['avatar_path'] = is_string($record['avatar_path'] ?? null) ? $record['avatar_path'] : null;
        $record['bio'] = is_string($record['bio'] ?? null) ? $record['bio'] : null;
        $record['bio_format'] = in_array($record['bio_format'] ?? null, ['html', 'markdown'], true) ? $record['bio_format'] : 'markdown';
        $record['website'] = is_string($record['website'] ?? null) ? $record['website'] : null;
        $record['location'] = is_string($record['location'] ?? null) ? $record['location'] : null;
        $record['locale'] = is_string($record['locale'] ?? null) && $record['locale'] !== '' ? $record['locale'] : 'en';
        $record['timezone'] = is_string($record['timezone'] ?? null) && $record['timezone'] !== '' ? $record['timezone'] : 'UTC';
        $preferences = json_decode((string) ($record['preferences'] ?? ''), true);
        $record['preferences'] = is_array($preferences) ? $preferences : [];
        $record['profile_visibility'] = in_array($record['preferences']['profile_visibility'] ?? null, ['public', 'members'], true)
            ? $record['preferences']['profile_visibility'] : 'public';
        return $record;
    }
}
