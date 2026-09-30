# Bluehost and traditional shared hosting

NovaNuke requires PHP 8.3 or newer and a MySQL/MariaDB database. Before uploading, run the complete tests on Laragon and build/install Composer dependencies locally if the hosting account does not offer Composer.

## Safe directory layout

Use a domain or subdomain whose document root points to the included `public/` directory. A typical account layout is:

```text
/home/account/novanuke/          application and .env
/home/account/novanuke/public/   domain document root
```

Do not expose `/home/account/novanuke/` itself and do not move `index.php` away from the supplied layout. If the plan cannot assign the document root to `public/`, request that change from hosting support before deployment.

## Upload and configuration

1. Upload the release outside the public document root and preserve dotfiles, including `public/.htaccess`, `public/.user.ini` and `public/uploads/.htaccess`.
2. Upload `vendor/` produced by `composer install --no-dev --optimize-autoloader` when Composer is unavailable on the server.
3. Create a dedicated database and database user with privileges only for that database.
4. For a fresh site, visit `/install`. For an existing site, preserve `.env`, `composer.lock`, `storage/installed.lock`, `storage/private/` and `public/uploads/`.
5. Use HTTPS and set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, `SESSION_SECURE=true` and `SECURITY_HEADERS_ENABLED=true`.
6. Give the PHP account write access to `storage/cache`, `storage/logs`, `storage/sessions`, `storage/private` and `public/uploads`. Avoid `0777`.
7. Run `php bin/cms production:check`. Correct every `FAIL`; review each `WARN`.

The included `.user.ini` disables displayed PHP errors, enables logging and strict cookie-only sessions. Some shared hosts cache `.user.ini` changes for several minutes. The public `.htaccess` denies direct access to dotfiles and upload handlers.

## Email

The log mailer is sufficient for private testing but does not deliver recovery or verification mail. Before enabling those public workflows, enter the SMTP hostname, port, username, password and encryption values supplied for the hosting account. Keep all credentials only in `.env`.

## Final verification

Run `migrate:status`, `i18n:check`, `security:audit`, `release:check` and `production:check`. Then verify login/logout, password recovery once SMTP is configured, module/theme administration, image uploads, private downloads, public menus, restricted blocks, maintenance mode and restoration from an off-server backup.
