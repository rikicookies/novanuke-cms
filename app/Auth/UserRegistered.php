<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final readonly class UserRegistered
{
    public function __construct(public int $userId, public bool $verificationRequired)
    {
    }
}
