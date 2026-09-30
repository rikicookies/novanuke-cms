<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Security\RateLimiter;

final class LoginThrottle implements RateLimiter
{
    private const KEY = '_login_attempts';

    public function __construct(
        private readonly SessionManager $session,
        private readonly int $maximumAttempts = 5,
        private readonly int $decaySeconds = 300,
    ) {
    }

    public function tooManyAttempts(string $key): bool
    {
        $record = $this->record($key);

        return $record['count'] >= $this->maximumAttempts;
    }

    public function hit(string $key): void
    {
        $attempts = $this->all();
        $record = $this->record($key);
        $attempts[$key] = ['count' => $record['count'] + 1, 'expires_at' => time() + $this->decaySeconds];
        $this->session->put(self::KEY, $attempts);
    }

    public function clear(string $key): void
    {
        $attempts = $this->all();
        unset($attempts[$key]);
        $this->session->put(self::KEY, $attempts);
    }

    public function retryAfter(string $key): int
    {
        return max(0, $this->record($key)['expires_at'] - time());
    }

    /** @return array{count: int, expires_at: int} */
    private function record(string $key): array
    {
        $record = $this->all()[$key] ?? ['count' => 0, 'expires_at' => time() + $this->decaySeconds];

        if ($record['expires_at'] <= time()) {
            return ['count' => 0, 'expires_at' => time() + $this->decaySeconds];
        }

        return $record;
    }

    /** @return array<string, array{count: int, expires_at: int}> */
    private function all(): array
    {
        $attempts = $this->session->get(self::KEY, []);

        return is_array($attempts) ? $attempts : [];
    }
}
