# Release verification

## Automated checks

```bash
composer validate
composer test:checkpoint
composer check:rc-source
composer check:site
php bin/cms cache:status
php bin/cms security:audit
php bin/cms migrate:status
php bin/cms production:check
```

`release:check` verifies required distribution files, separation of application code from `public/`, absence of unexpected PHP/backups/secrets in the public tree and empty secret fields in `.env.example`.

`migrate:status` is read-only. It returns a non-zero exit status when core or module migrations are pending, executed migration files are absent, or copied module versions still need their controlled panel update.

Integration tests create and remove random `novanuke_test_*` databases. Run them on Laragon or another disposable development database server, never on production hosting.

## Fresh-install smoke test

- Start with no `.env`, no installation lock and an empty database.
- Complete `/install` and create the first Super Administrator.
- Confirm installer routes are locked afterwards.
- Sign in/out and exercise an invalid-login rate limit.
- Review users, roles, logs, modules, themes, blocks, menus and `/admin/system`.
- Install and enable bundled modules, then exercise public module routes.

## Existing-site update smoke test

- Preserve `.env`, lock, Composer lock and all private data.
- Back up, enable maintenance, update, migrate and clear cache.
- Confirm active theme, module state, blocks and menus were preserved.
- Confirm existing users, content and permissions remain unchanged.

## Security smoke test

- Invalid/missing CSRF tokens return 419 and make no change.
- Every `/admin` URL redirects guests to login and returns 403 to accounts without `admin.access`.
- Accounts with `admin.access` still receive 403 when they lack the operation-specific permission.
- Suspended users lose authenticated access.
- Guest/Member show no dangerous grants in the authorization audit.
- Production errors reveal only a reference ID.
- Downloads reject disallowed MIME, extension, size and traversal attempts.
- Custom HTML is sanitized and cannot execute PHP or JavaScript.
- Maintenance returns 503 and does not block administrative recovery.

## Production smoke test

- HTTPS, secure session cookies and security headers are active.
- SMTP recovery and verification arrive and one-time links cannot be reused.
- Database and private-file restore is tested away from production.
- Apache/Nginx serves only `public/`.
- Detailed PHP display errors are disabled at application and server levels.

Record PHP, MySQL/MariaDB and Composer versions with the test results. Required failures must block public deployment until corrected.

## Release package smoke check

Before uploading or extracting a release, run:

```bash
php bin/cms release:smoke
```

This command executes before application bootstrap and does not require a database. It checks distribution completeness, release/version metadata, bundled module manifests, migration file safety and private-storage guards. A release archive that fails this command should not be deployed.

After extraction, continue with `release:check`, `migrate:status`, `production:check`, `security:audit` and the normal upgrade completion workflow.

`rc:check` is a database-independent source-package gate that rejects local `.env`, installation locks, runtime logs/cache/backups, stale release metadata and incomplete RC documentation.

The complete Release Candidate acceptance matrix is in `docs/RC_ACCEPTANCE.md`. PHPUnit/distribution checks are necessary but do not replace fresh-install, real-upgrade, restore, SMTP and production-host acceptance.
