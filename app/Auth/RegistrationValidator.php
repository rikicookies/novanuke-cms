<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final class RegistrationValidator
{
    public function __construct(private readonly PasswordPolicy $passwords)
    {
    }

    /** @param array<string, mixed> $input
     *  @return array<string, string>
     */
    public function validate(array $input): array
    {
        $errors = [];
        $username = trim((string) ($input['username'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));

        if (! preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $errors['username'] = 'Use 3-32 letters, numbers, dots, underscores or hyphens.';
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $passwordError = $this->passwords->validate(
            $input['password'] ?? null,
            $input['password_confirmation'] ?? null,
        );
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }

        return $errors;
    }
}
