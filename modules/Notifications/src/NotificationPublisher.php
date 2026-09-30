<?php

declare(strict_types=1);

namespace Modules\Notifications\src;

use InvalidArgumentException;

final class NotificationPublisher
{
    public function __construct(private readonly NotificationRepository $repository)
    {
    }

    public function toUser(int $userId, string $type, string $title, string $message, ?string $url = null, ?string $key = null): void
    {
        if ($userId < 1) throw new InvalidArgumentException('Notification user is invalid.');
        $type = trim($type); $title = trim($title); $message = trim($message);
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,63}$/', $type)) throw new InvalidArgumentException('Notification type is invalid.');
        if ($title === '' || mb_strlen($title) > 160) throw new InvalidArgumentException('Notification title is invalid.');
        if ($message === '' || mb_strlen($message) > 500) throw new InvalidArgumentException('Notification message is invalid.');
        if ($url !== null && (! str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\x00-\x1F\x7F]/', $url))) {
            throw new InvalidArgumentException('Notification URL must be an internal path.');
        }
        if ($key !== null && ($key === '' || mb_strlen($key) > 191)) throw new InvalidArgumentException('Notification key is invalid.');
        $this->repository->insert($userId, $type, $title, $message, $url, $key);
    }

    public function toPermission(string $permission, string $type, string $title, string $message, ?string $url = null, ?string $key = null): void
    {
        foreach ($this->repository->usersWithPermission($permission) as $userId) {
            $this->toUser($userId, $type, $title, $message, $url, $key);
        }
    }
}
