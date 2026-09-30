# Email delivery

NovaNuke supports two transports:

- `log` for Laragon/development only;
- authenticated encrypted `smtp` for production.

PHPMailer is used as the small, dedicated SMTP implementation. NovaNuke keeps message creation and the `Mailer` contract in the core, so authentication services do not depend directly on PHPMailer.

The mail contract covers password recovery, initial address verification and confirmation of account email changes. Development can exercise all three flows through `storage/logs/mail.log`.

## Install the dependency

Phase 7C changes `composer.json`. After copying the new files while preserving your existing `.env` and `composer.lock`, run this once:

```bash
composer update phpmailer/phpmailer
```

This adds PHPMailer to the lock file. Afterwards normal deployments use:

```bash
composer install --no-dev --optimize-autoloader
```

## Bluehost cPanel example

Create the mailbox first, then open Bluehost **Hosting → cPanel Email → Email Accounts → Connect Devices** and use the exact manual outgoing-server values shown there.

Typical secure cPanel values are:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=example.com
MAIL_PORT=465
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD="the mailbox password"
MAIL_ENCRYPTION=ssl
MAIL_TIMEOUT=15
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="NovaNuke"
```

Replace every example. Bluehost currently recommends authenticated SSL/TLS on port 465 for cPanel email. If the account specifically provides port 587, use `MAIL_ENCRYPTION=tls` instead. Never place these values in the repository.

## Verification levels

NovaNuke intentionally distinguishes three different claims:

1. `php bin/cms mail:check` — configuration is structurally valid. This can pass with `MAIL_MAILER=log` in development/test and never claims a message was delivered.
2. `php bin/cms production:check` — production mail is ready. This requires `MAIL_MAILER=smtp` plus structurally valid SMTP configuration.
3. `php bin/cms mail:acceptance` — delivery has been manually accepted for the three real account workflows. Until all three are recorded, RC deployment remains `MANUAL REQUIRED / NOT VERIFIED`.

On a disposable production-like environment, exercise and confirm all three workflows:

- create/register a controlled account and confirm the registration-verification message arrives;
- request password recovery and confirm the reset message arrives;
- request an account email change and confirm the change-verification message arrives.

For every workflow confirm the expected recipient and sender, the HTTPS site URL in the link, and that the token succeeds once and cannot be reused. Only after the real workflow succeeds, record that acceptance:

```bash
php bin/cms mail:acceptance --record=registration-verification
php bin/cms mail:acceptance --record=password-reset
php bin/cms mail:acceptance --record=email-change
php bin/cms mail:acceptance
```

The acceptance file is bound to the current SMTP settings and effective site URL. Changing those values invalidates the previous acceptance instead of carrying a stale PASS into another deployment. The state file is private and contains only a configuration fingerprint and verification timestamps; it does not store the SMTP password.

Check spam delivery and configure SPF, DKIM and DMARC through the hosting/email provider. NovaNuke never writes SMTP debug conversations, usernames or passwords to its activity log. User-facing delivery failures remain generic; detailed exceptions go only through production-safe server logging.
