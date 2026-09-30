<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

use NovaNuke\Core\I18n\Translator;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class SmtpMailer implements Mailer
{
    private readonly MailSiteIdentity $siteIdentity;

    public function __construct(
        private readonly SmtpConfiguration $configuration,
        ?MailSiteIdentity $siteIdentity = null,
        private readonly ?Translator $translator = null,
    ) {
        $this->siteIdentity = $siteIdentity ?? new MailSiteIdentity($configuration->fromName);
    }

    public function sendPasswordReset(string $recipient, string $resetUrl, int $expiresInMinutes): void
    {
        $this->send(
            $recipient,
            $this->translate('mail.password_reset.subject', ['site_name' => $this->siteIdentity->name()], 'Reset your password — ' . $this->siteIdentity->name()),
            $this->translate('mail.password_reset.heading', ['site_name' => $this->siteIdentity->name()], $this->siteIdentity->name() . ' password reset'),
            $this->translate('mail.password_reset.intro', ['minutes' => $expiresInMinutes], "Open the link below within {$expiresInMinutes} minutes to choose a new password."),
            $resetUrl,
            $this->translate('mail.password_reset.footer', [], 'If you did not request this reset, ignore this message.'),
        );
    }

    public function sendEmailVerification(string $recipient, string $verificationUrl, int $expiresInMinutes): void
    {
        $this->send(
            $recipient,
            $this->translate('mail.verification.subject', ['site_name' => $this->siteIdentity->name()], 'Verify your ' . $this->siteIdentity->name() . ' account'),
            $this->translate('mail.verification.heading', ['site_name' => $this->siteIdentity->name()], 'Verify your ' . $this->siteIdentity->name() . ' account'),
            $this->translate('mail.verification.intro', ['minutes' => $expiresInMinutes], "Open the link below within {$expiresInMinutes} minutes to verify your email address."),
            $verificationUrl,
            $this->translate('mail.verification.footer', [], 'If you did not create this account, ignore this message.'),
        );
    }

    public function sendEmailChangeVerification(string $recipient, string $verificationUrl, int $expiresInMinutes): void
    {
        $this->send(
            $recipient,
            $this->translate('mail.email_change.subject', ['site_name' => $this->siteIdentity->name()], 'Confirm your new email — ' . $this->siteIdentity->name()),
            $this->translate('mail.email_change.heading', ['site_name' => $this->siteIdentity->name()], 'Confirm your ' . $this->siteIdentity->name() . ' email change'),
            $this->translate('mail.email_change.intro', ['minutes' => $expiresInMinutes], "Open the link below within {$expiresInMinutes} minutes to make this your account email address."),
            $verificationUrl,
            $this->translate('mail.email_change.footer', [], 'If you did not request this change, ignore this message and your current address will remain active.'),
        );
    }

    public function sendMessage(string $recipient, string $subject, string $body, ?string $replyTo = null): void
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The recipient email address is invalid.');
        if ($replyTo !== null && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The reply-to email address is invalid.');
        $subject = trim(str_replace(["\r", "\n"], '', $subject));
        if ($subject === '' || mb_strlen($subject) > 200 || mb_strlen($body) > 20000) throw new RuntimeException('The email message is invalid.');
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP(); $mail->Host=$this->configuration->host; $mail->Port=$this->configuration->port; $mail->SMTPAuth=true;
            $mail->Username=$this->configuration->username; $mail->Password=$this->configuration->password;
            $mail->SMTPSecure=$this->configuration->encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS=false; $mail->Timeout=$this->configuration->timeout; $mail->SMTPDebug=0; $mail->CharSet=PHPMailer::CHARSET_UTF8;
            $mail->setFrom($this->configuration->fromAddress,$this->configuration->fromName); $mail->addAddress($recipient); if($replyTo!==null) $mail->addReplyTo($replyTo);
            $mail->Subject=$subject; $mail->isHTML(false); $mail->Body=$body; $mail->AltBody=$body; $mail->send();
        } catch (PHPMailerException $error) { throw new RuntimeException('The SMTP server could not deliver the message.', previous: $error); }
    }

    private function send(string $recipient, string $subject, string $heading, string $intro, string $url, string $footer): void
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The recipient email address is invalid.');
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new RuntimeException('The email action URL is invalid.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->configuration->host;
            $mail->Port = $this->configuration->port;
            $mail->SMTPAuth = true;
            $mail->Username = $this->configuration->username;
            $mail->Password = $this->configuration->password;
            $mail->SMTPSecure = $this->configuration->encryption === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = false;
            $mail->Timeout = $this->configuration->timeout;
            $mail->SMTPDebug = 0;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($this->configuration->fromAddress, $this->configuration->fromName);
            $mail->addAddress($recipient);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $safeHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeIntro = htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeFooter = htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeAction = htmlspecialchars($this->translate('mail.continue', [], 'Continue'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $mail->Body = "<h1>{$safeHeading}</h1><p>{$safeIntro}</p><p><a href=\"{$safeUrl}\">{$safeAction}</a></p><p>{$safeFooter}</p>";
            $mail->AltBody = "{$heading}\n\n{$intro}\n{$url}\n\n{$footer}";
            $mail->send();
        } catch (PHPMailerException $error) {
            throw new RuntimeException('The SMTP server could not deliver the message.', previous: $error);
        }
    }

    /** @param array<string, scalar|null> $parameters */
    private function translate(string $key, array $parameters, string $fallback): string
    {
        return $this->translator?->translate($key, $parameters) ?? $fallback;
    }
}
