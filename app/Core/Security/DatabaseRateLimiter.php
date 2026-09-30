<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class DatabaseRateLimiter implements RateLimiter
{
    public function __construct(
        private readonly PDO $database,
        private readonly int $maximumAttempts = 5,
        private readonly int $decaySeconds = 300,
        private readonly string $prefix = 'default',
    ) {
        if ($this->maximumAttempts < 1 || $this->maximumAttempts > 100
            || $this->decaySeconds < 1 || $this->decaySeconds > 86400
            || $this->prefix === '' || strlen($this->prefix) > 64) {
            throw new \InvalidArgumentException('Rate limiter configuration is outside safe bounds.');
        }
    }

    public function tooManyAttempts(string $key): bool
    {
        $record = $this->record($key);

        return $record !== null && $record['attempts'] >= $this->maximumAttempts;
    }

    public function hit(string $key): void
    {
        $this->assertKey($key);
        // Expired rows are cheap to replace in-place. Periodic cleanup avoids a full DELETE on every request.
        if (random_int(1, 100) === 1) {
            $this->database->exec('DELETE FROM rate_limits WHERE window_ends_at <= UTC_TIMESTAMP()');
        }
        $window = gmdate('Y-m-d H:i:s', time() + $this->decaySeconds);
        $statement = $this->database->prepare(
            'INSERT INTO rate_limits (key_hash, attempts, window_ends_at, updated_at) '
            . 'VALUES (:key_hash, 1, :window_ends_at, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'attempts = IF(window_ends_at <= UTC_TIMESTAMP(), 1, attempts + 1), '
            . 'window_ends_at = IF(window_ends_at <= UTC_TIMESTAMP(), VALUES(window_ends_at), window_ends_at), '
            . 'updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute(['key_hash' => $this->hash($key), 'window_ends_at' => $window]);
    }

    public function clear(string $key): void
    {
        $this->assertKey($key);
        $statement = $this->database->prepare('DELETE FROM rate_limits WHERE key_hash = :key_hash');
        $statement->execute(['key_hash' => $this->hash($key)]);
    }

    public function retryAfter(string $key): int
    {
        $record = $this->record($key);
        if ($record === null) {
            return 0;
        }

        $end = new DateTimeImmutable($record['window_ends_at'], new DateTimeZone('UTC'));

        return max(0, $end->getTimestamp() - time());
    }

    /** @return array{attempts: int, window_ends_at: string}|null */
    private function record(string $key): ?array
    {
        $this->assertKey($key);
        $statement = $this->database->prepare(
            'SELECT attempts, window_ends_at FROM rate_limits '
            . 'WHERE key_hash = :key_hash AND window_ends_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $statement->execute(['key_hash' => $this->hash($key)]);
        $record = $statement->fetch();

        return is_array($record)
            ? ['attempts' => (int) $record['attempts'], 'window_ends_at' => (string) $record['window_ends_at']]
            : null;
    }

    private function hash(string $key): string
    {
        return hash('sha256', $this->prefix . '|' . $key);
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || strlen($key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new \InvalidArgumentException('Rate limiter key is invalid.');
        }
    }
}
