<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

final class MailSiteIdentity
{
    private readonly string $name;

    public function __construct(string $name)
    {
        $singleLine = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name) ?? '';
        $singleLine = preg_replace('/\s+/', ' ', trim($singleLine)) ?? '';
        $this->name = $singleLine !== '' ? mb_substr($singleLine, 0, 100) : 'NovaNuke';
    }

    public function name(): string
    {
        return $this->name;
    }
}
