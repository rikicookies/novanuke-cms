<?php

declare(strict_types=1);

namespace NovaNuke\Auth;

final class VerificationResendInput
{
    /** @return array{email:string,error:?string} */
    public function validate(mixed $email): array
    {
        $normalized = is_string($email) ? strtolower(trim($email)) : '';
        $valid = $normalized !== '' && strlen($normalized) <= 254
            && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false;

        return ['email' => $normalized, 'error' => $valid ? null : 'Enter a valid email address.'];
    }
}
