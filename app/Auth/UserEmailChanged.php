<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final readonly class UserEmailChanged
{
    public function __construct(public int $userId)
    {
    }
}
