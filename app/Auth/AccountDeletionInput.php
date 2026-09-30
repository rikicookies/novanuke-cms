<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final class AccountDeletionInput
{
    /** @return array<string,string> */
    public function validate(string $username, mixed $confirmation, mixed $password): array
    {
        $errors = [];
        if (! is_string($confirmation) || ! hash_equals($username, trim($confirmation))) {
            $errors['confirmation'] = 'Enter your username exactly to confirm account deletion.';
        }
        if (! is_string($password) || $password === '' || strlen($password) > 255) {
            $errors['password'] = 'Enter your current password.';
        }

        return $errors;
    }
}
