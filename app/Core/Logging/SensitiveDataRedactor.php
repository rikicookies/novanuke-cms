<?php

declare(strict_types=1);

namespace NovaNuke\Core\Logging;

final class SensitiveDataRedactor
{
    public function redact(string $message): string
    {
        $message = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 [REDACTED]', $message) ?? '[REDACTED]';
        $message = preg_replace(
            '/\b(password|passwd|pwd|token|secret|authorization|cookie|api[_-]?key)\b\s*([:=])\s*([^\s,;]+)/i',
            '$1$2[REDACTED]',
            $message,
        ) ?? '[REDACTED]';
        $message = preg_replace('#(https?://[^:/\s]+:)[^@/\s]+@#i', '$1[REDACTED]@', $message) ?? '[REDACTED]';
        $message = preg_replace('/\b[a-f0-9]{64}\b/i', '[REDACTED_TOKEN]', $message) ?? '[REDACTED]';

        return preg_replace('/\R+/', ' ', $message) ?? '[REDACTED]';
    }
}
