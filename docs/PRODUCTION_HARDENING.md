# Production hardening

NovaNuke Beta 1 treats production deployment as a separate configuration from local development.

## Required environment baseline

Use HTTPS and set at minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
SESSION_SECURE=true
SESSION_SAME_SITE=Lax
SESSION_LIFETIME=7200
SESSION_IDLE_TIMEOUT=1800
SESSION_ROTATION_INTERVAL=900
SESSION_PATH=/
SESSION_DOMAIN=
SECURITY_HEADERS_ENABLED=true
```

`SESSION_SAME_SITE=None` is allowed only with `SESSION_SECURE=true`. Prefer `Lax` unless a real cross-site flow requires `None`.

Run `php bin/cms production:check` after deployment and after changing the PHP handler, document root or session configuration.

## Document root

Point the virtual host/document root at NovaNuke's `public/` directory. Do not expose the project root, `.env`, `vendor/`, `storage/`, `config/` or source directories directly through the web server.

On Apache/shared hosting, keep both distributed files:

- `public/.htaccess`
- `public/uploads/.htaccess`

The upload guard disables directory indexes and CGI/script execution patterns. Do not weaken it to make an uploaded executable file directly accessible.

## Sessions

Beta 1 uses three independent controls:

- `SESSION_LIFETIME`: maximum lifetime of a session, default 7200 seconds.
- `SESSION_IDLE_TIMEOUT`: maximum idle period, default 1800 seconds.
- `SESSION_ROTATION_INTERVAL`: interval between session-ID rotations, default 900 seconds.

Authentication still regenerates the session ID on successful login and invalidates it on logout.

Production cookie scope should remain `SESSION_PATH=/` with an empty `SESSION_DOMAIN`. Once HTTPS is confirmed, a `__Host-` prefixed `SESSION_NAME` is recommended; NovaNuke rejects a `__Host-` session name unless Secure is enabled, path is `/` and Domain is empty.

## Browser caching

Admin, account, authentication and error surfaces default to `Cache-Control: no-store, private`. Routes that intentionally provide a stronger explicit cache policy keep their own header.

## Uploads

NovaNuke validates extension, MIME type and size before storing supported uploads. Public upload storage is additionally protected by web-server rules. Private assets such as avatars and Wiki-managed private files must continue to be served through application-controlled responses when their storage implementation requires authorization.

## Before public traffic

Run:

```bash
composer test
composer test:integration
php bin/cms production:check
php bin/cms rc:deployment
php bin/cms security:audit
php bin/cms release:check
php bin/cms migrate:status
```

All required production checks should pass before directing public traffic to the deployment.

## File storage boundaries

Beta 3 centralizes path containment checks for private and uploaded files. Do not serve `storage/private` directly from the web server; Downloads and Wiki attachments must pass application authorization before their stored path is resolved. Public avatars remain intentionally public assets, while Media files stay under the hardened `public/uploads` tree.

## Request abuse and error disclosure

Beta 4 caps request collection size/nesting before routing. Core/admin state-changing POST surfaces remain CSRF protected. Explicit throttles should respond with HTTP 429 and `Retry-After`. Production exception pages expose a reference ID rather than exception details; application paths in the NovaNuke log are normalized to `[APP]/...`.

## Installer recovery and secret files

Beta 5 makes fresh installation retry-safe after NovaNuke has verified that the selected database is empty. If installation fails after that ownership point, NovaNuke removes only the tables created by that attempt and any incomplete `.env`; it never drops the database itself.

On POSIX production hosts `.env` and generated backup files should be owner-only (`0600` or equivalent). `production:check` validates `.env` permissions. `storage/private/.htaccess` is included as defense in depth for Apache/shared-hosting setups, but the correct deployment remains a document root pointed at `public/`.

## Backup boundaries

The private backup directory must be a real directory, not a symlink. Generated database/file backups remain atomic and owner-only. `backup:verify` rejects symlinked/non-regular backup files and, on POSIX hosts, files readable or writable by group/others.
