# Production deployment

Treat production deployment as a separate acceptance target from local development. Test a complete backup and restore before every deployment or update.

## Required production environment

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
SESSION_SECURE=true
SESSION_SAME_SITE=Lax
SECURITY_HEADERS_ENABLED=true
SECURITY_HSTS_ENABLED=false
```

Enable HSTS only after HTTPS works correctly on the production domain and every required subdomain. HSTS can make an incorrectly configured site inaccessible until the browser policy expires.

The `log` mailer is development-only. Configure and test encrypted SMTP before enabling registration, email verification or password recovery in production. See `docs/MAIL.md`.

## Files and permissions

- Point the web document root to `public/`, never to the project root.
- Keep `.env`, `vendor/`, `storage/private/`, migrations and source code outside public access.
- Give the web/PHP user write access only where needed: `storage/cache`, `storage/logs`, `storage/sessions`, `storage/private` and published theme assets.
- Do not use world-writable permissions such as `0777` unless a host leaves no safer option, and then resolve it with the host.
- Keep `storage/installed.lock` after every update.

## Apache and Laragon

Set the virtual host document root to `/path/to/novanuke/public` and allow overrides for that directory so `public/.htaccess` can route friendly URLs. Laragon normally creates this mapping automatically when the project directory is configured as a site; verify its document root rather than assuming it.

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    root /path/to/novanuke/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~* ^/uploads/.*\.(php[0-9]?|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }

    location ~ /\. { deny all; }
}
```

Adjust the PHP-FPM socket to the installed PHP version. TLS certificate setup is server-specific.

## Traditional shared hosting / Bluehost

Prefer a domain or subdomain whose document root can be assigned directly to `novanuke/public`. Keep the rest of NovaNuke one level outside the public document directory. Do not copy only `public/index.php` elsewhere: it resolves the application root relative to its original directory layout.

If the hosting plan cannot point a domain at `public/`, ask the host to change the document root before deployment. Exposing the entire project to compensate is unsafe.

## Release procedure

1. Run `php bin/cms rc:check` and `php bin/cms release:smoke` against the clean release package/source tree before deployment.
2. Put the site in maintenance mode from `/admin/settings`.
3. Create database and file backups with `backup:database` and `backup:files`, verify them with `backup:verify`, then move protected copies off-server.
4. Preserve `.env`, `composer.lock`, `storage/installed.lock` and all persistent private/upload data.
5. Replace application files and run `composer install --no-dev --optimize-autoloader`.
6. Run the supported upgrade preflight with the exact currently recorded source version.
7. Run `php bin/cms migrate:status`, then `php bin/cms migrate`.
8. Apply compatible module/theme updates and run `php bin/cms migrate:status` again.
9. Clear generated caches if instructed by the release notes.
10. Complete the upgrade with `upgrade:complete --from=CURRENT_INSTALLED_VERSION`.
11. Run `composer check:site`.
12. Run `php bin/cms security:audit` and correct every failed authorization check.
13. Run `composer check:release` and correct every required production failure.
14. Visit `/admin/system` and smoke-test authentication, permissions, uploads, email workflows and enabled module routes.
15. Disable maintenance mode only after acceptance is complete.

## Maintenance and cache

Maintenance mode is controlled from `/admin/settings`. Public requests receive HTTP 503 with `Retry-After` and `no-store`; login, password recovery, health checks and administrative routes remain reachable. A signed-in Super Administrator can preview public pages while maintenance is active.

After deploying changed PHP or Twig files, clear generated caches:

```bash
php bin/cms cache:status
php bin/cms cache:clear
```

The clear command is restricted to `storage/cache`, preserves the cache root and resets OPcache when PHP permits it.

Run the data-retention command regularly after first checking its dry-run output. See `docs/MAINTENANCE.md` for Laragon, cron and shared-hosting examples.

For local download storage, run `php bin/cms downloads:orphans` after backups. Use `--delete` only after reviewing the eligible count; new files receive a 24-hour grace period.


## Final release validation

For an already-installed site that is intended to go public, run:

```bash
composer check:release
```

Do not use `install:check` as an installed-site health check. The installer deliberately expects `.env` and `storage/installed.lock` to be absent. For development/staging installations use `composer check:site`.


## Mail configuration preflight

Before testing real delivery, run:

```bash
php bin/cms mail:check
php bin/cms mail:acceptance
```

`mail:check` checks the selected transport and SMTP configuration structure without sending a message. `production:check` requires SMTP and fails when `MAIL_MAILER=log`. A structurally valid SMTP configuration is still not delivery evidence: `mail:acceptance` remains `MANUAL REQUIRED / NOT VERIFIED` until registration verification, password reset, and email-change verification have each been exercised on the production-like host and explicitly recorded. See `docs/MAIL.md` and `docs/RC_ACCEPTANCE.md`.
