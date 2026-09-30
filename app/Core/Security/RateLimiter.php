<?php

declare(strict_types=1);

namespace NovaNuke\Core\Security;

interface RateLimiter
{
    public function tooManyAttempts(string $key): bool;

    public function hit(string $key): void;

    public function clear(string $key): void;

    public function retryAfter(string $key): int;
}
