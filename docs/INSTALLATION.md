# Installation

## Before starting

Use PHP 8.3+, Composer 2 and MySQL/MariaDB. The web document root must be NovaNuke's `public/` directory. Internal source, `.env`, logs and private files must never be directly served.

Enable PHP's ZIP extension when the site needs Wiki's complete Markdown archive export. The CMS and individual Wiki page export continue to work without it.

## Laragon

1. Extract NovaNuke to a dedicated directory such as `C:\\dev\\www\\novanuke`.
2. Open a terminal in that directory and run `composer install`.
3. Run `php bin/cms install:check`. NovaNuke creates its required runtime directories automatically, including `storage/private/downloads` and `storage/private/backups`; resolve only permissions or other failed requirements it reports.
4. Create or select an empty database. The installer refuses a database containing any table.
5. Configure the Laragon virtual host document root as `C:\\dev\\www\\novanuke\\public`.
6. Ensure Apache rewrite support is enabled.
7. Open the generated local URL and follow `/install`.
8. Enter the database and first Super Administrator values.
9. Confirm `storage/installed.lock` exists and `/install` no longer loads.
10. Run `composer test` and `php bin/cms release:check`.

Typical Laragon MySQL values are `127.0.0.1`, port `3306`, user `root`, empty password and database `novanuke`.

Do not copy `.env.example` to `.env` before a fresh installer test. The installer generates a unique application key and writes credentials atomically. It never replaces an existing `.env`; review and remove that file manually only when intentionally resetting a disposable installation.

The CLI currently audits readiness but does not accept installation credentials. Complete account creation through the web installer so passwords do not appear in shell history or process arguments.

## Apache/shared hosting

Install Composer dependencies locally if the host does not provide Composer, then transfer the complete application including `vendor/`. Prefer a Bluehost domain or subdomain whose document root can be assigned to `/home/account/novanuke/public`.

Keep the application itself outside `public_html` whenever possible. Do not expose the project root as a workaround for an inflexible hosting plan.

## Nginx

Use the `try_files` and PHP-FPM example in `docs/PRODUCTION.md`. `.htaccess` applies only to Apache.

## First checks

- Sign in and open `/admin/system`.
- Confirm the authorization audit passes.
- Install/enable required modules and select the active theme.
- Keep registration closed until email delivery and moderation settings are ready.
- Create the first backup and test restoring it on another database.

## Failed installation recovery

After NovaNuke verifies that the selected database contains no tables, it treats tables created during that installation attempt as installer-owned. If a later installation step fails, NovaNuke removes those newly created tables and any incomplete `.env` so the same empty database can be retried. The database itself is never dropped. If the cleanup cannot complete, the next attempt will stop at the normal non-empty database guard rather than overwriting data.
