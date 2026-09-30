<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final class LoginValidator
{
    /** @param array<string, mixed> $input
     *  @return array<string, string>
     */
    public function validate(array $input): array
    {
        $errors = [];
        $login = trim((string) ($input['login'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($login === '' || mb_strlen($login) > 254) {
            $errors['login'] = 'Enter your username or email address.';
        }
        if ($password === '' || strlen($password) > 255) {
            $errors['password'] = 'Enter your password.';
        }

        return $errors;
    }
}
