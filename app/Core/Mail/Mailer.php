<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

interface Mailer
{
    public function sendPasswordReset(string $recipient, string $resetUrl, int $expiresInMinutes): void;

    public function sendEmailVerification(string $recipient, string $verificationUrl, int $expiresInMinutes): void;

    public function sendEmailChangeVerification(string $recipient, string $verificationUrl, int $expiresInMinutes): void;

    public function sendMessage(string $recipient, string $subject, string $body, ?string $replyTo = null): void;
}
