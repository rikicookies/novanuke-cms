<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

use InvalidArgumentException;

final class SmtpConfiguration
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $encryption,
        public readonly int $timeout,
        public readonly string $fromAddress,
        public readonly string $fromName,
    ) {
        $hostIsIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $hostIsName = preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]+(?:\.(?!-)[a-z0-9-]+)*$/i', $host) === 1;
        if (! $hostIsIp && ! $hostIsName) throw new InvalidArgumentException('MAIL_HOST must be a valid hostname or IP address.');
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('MAIL_PORT must be between 1 and 65535.');
        if ($username === '' || $password === '') throw new InvalidArgumentException('SMTP username and password are required.');
        if (! in_array($encryption, ['tls', 'ssl'], true)) throw new InvalidArgumentException('MAIL_ENCRYPTION must be tls or ssl.');
        if ($timeout < 5 || $timeout > 60) throw new InvalidArgumentException('MAIL_TIMEOUT must be between 5 and 60 seconds.');
        if (! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('MAIL_FROM_ADDRESS must be a valid email address.');
        if ($fromName === '' || preg_match('/[\r\n]/', $fromName)) throw new InvalidArgumentException('MAIL_FROM_NAME is invalid.');
    }
}
