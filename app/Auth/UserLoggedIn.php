<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final readonly class UserLoggedIn
{
    public function __construct(public int $userId)
    {
    }
}
