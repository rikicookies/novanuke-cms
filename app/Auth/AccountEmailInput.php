<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final class AccountEmailInput
{
    /** @return array{email:string,password:string,errors:array<string,string>} */
    public function validate(mixed $email, mixed $password): array
    {
        $normalized = is_string($email) ? strtolower(trim($email)) : '';
        $secret = is_string($password) ? $password : '';
        $errors = [];
        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($secret === '' || strlen($secret) > 255) $errors['password'] = 'Enter your current password.';

        return ['email' => $normalized, 'password' => $secret, 'errors' => $errors];
    }
}
