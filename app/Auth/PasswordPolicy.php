<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final class PasswordPolicy
{
    public function validate(mixed $password, mixed $confirmation): ?string
    {
        if (! is_string($password) || strlen($password) < 12 || strlen($password) > 255) {
            return 'Use a password between 12 and 255 characters.';
        }

        if (! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            return 'The passwords do not match.';
        }

        return null;
    }
}
