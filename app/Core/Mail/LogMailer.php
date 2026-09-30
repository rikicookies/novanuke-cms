<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

use NovaNuke\Core\I18n\Translator;
use RuntimeException;

final class LogMailer implements Mailer
{
    private readonly MailSiteIdentity $siteIdentity;

    public function __construct(
        private readonly string $path,
        private readonly string $environment,
        private readonly string $fromAddress,
        private readonly string $fromName,
        ?MailSiteIdentity $siteIdentity = null,
        private readonly ?Translator $translator = null,
    ) {
        $this->siteIdentity = $siteIdentity ?? new MailSiteIdentity($fromName);
    }

    public function sendPasswordReset(string $recipient, string $resetUrl, int $expiresInMinutes): void
    {
        $this->write(
            $recipient,
            $this->translate('mail.password_reset.subject', ['site_name' => $this->siteIdentity->name()], 'Reset your password — ' . $this->siteIdentity->name()),
            $resetUrl,
            $expiresInMinutes,
            $this->translate('mail.password_reset.footer', [], 'If you did not request this reset, ignore this message.'),
        );
    }

    public function sendEmailVerification(string $recipient, string $verificationUrl, int $expiresInMinutes): void
    {
        $this->write(
            $recipient,
            $this->translate('mail.verification.subject', ['site_name' => $this->siteIdentity->name()], 'Verify your ' . $this->siteIdentity->name() . ' account'),
            $verificationUrl,
            $expiresInMinutes,
            $this->translate('mail.verification.footer', [], 'If you did not create this account, ignore this message.'),
        );
    }

    public function sendEmailChangeVerification(string $recipient, string $verificationUrl, int $expiresInMinutes): void
    {
        $this->write(
            $recipient,
            $this->translate('mail.email_change.subject', ['site_name' => $this->siteIdentity->name()], 'Confirm your new email — ' . $this->siteIdentity->name()),
            $verificationUrl,
            $expiresInMinutes,
            $this->translate('mail.email_change.footer', [], 'If you did not request this email change, ignore this message and your current address will remain active.'),
        );
    }

    public function sendMessage(string $recipient, string $subject, string $body, ?string $replyTo = null): void
    {
        if ($this->environment === 'production') throw new RuntimeException('The log mailer is disabled in production. Configure SMTP first.');
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ($replyTo !== null && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL))) throw new RuntimeException('The email address is invalid.');
        $subject=$this->singleLine(trim($subject)); if($subject==='' || mb_strlen($subject)>200 || mb_strlen($body)>20000) throw new RuntimeException('The email message is invalid.');
        $lines=['------------------------------------------------------------','Date: '.gmdate('c'),'From: '.$this->singleLine($this->fromName).' <'.$this->singleLine($this->fromAddress).'>','To: '.$this->singleLine($recipient),'Subject: '.$subject];
        if($replyTo!==null) $lines[]='Reply-To: '.$this->singleLine($replyTo); $lines[]=''; $lines[]=$body; $lines[]='------------------------------------------------------------'; $lines[]='';
        if(file_put_contents($this->path,implode(PHP_EOL,$lines),FILE_APPEND|LOCK_EX)===false) throw new RuntimeException('The development email could not be written.');
    }

    private function singleLine(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function write(
        string $recipient,
        string $subject,
        string $url,
        int $expiresInMinutes,
        string $footer,
    ): void {
        if ($this->environment === 'production') {
            throw new RuntimeException('The log mailer is disabled in production. Configure SMTP first.');
        }

        $message = implode(PHP_EOL, [
            '------------------------------------------------------------',
            'Date: ' . gmdate('c'),
            'From: ' . $this->singleLine($this->fromName) . ' <' . $this->singleLine($this->fromAddress) . '>',
            'To: ' . $this->singleLine($recipient),
            'Subject: ' . $this->singleLine($subject),
            '',
            $this->translate('mail.action_link', ['minutes' => $expiresInMinutes], "Open this one-time link within {$expiresInMinutes} minutes:"),
            $url,
            $footer,
            '------------------------------------------------------------',
            '',
        ]);

        if (file_put_contents($this->path, $message, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('The development email could not be written.');
        }
    }

    /** @param array<string, scalar|null> $parameters */
    private function translate(string $key, array $parameters, string $fallback): string
    {
        return $this->translator?->translate($key, $parameters) ?? $fallback;
    }
}
