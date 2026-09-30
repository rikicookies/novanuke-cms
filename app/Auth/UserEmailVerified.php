<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final readonly class UserEmailVerified
{
    public function __construct(public int $userId)
    {
    }
}
