# Production security checklist

- [ ] PHP 8.3+ and all required extensions are supported and patched.
- [ ] The document root is exactly `public/`.
- [ ] `APP_ENV=production` and `APP_DEBUG=false`.
- [ ] `APP_URL` is the canonical HTTPS URL.
- [ ] `SESSION_SECURE=true`; session cookies remain HTTP-only and SameSite.
- [ ] Production session scope uses `SESSION_PATH=/` and an empty `SESSION_DOMAIN`; consider a `__Host-` session name after HTTPS validation.
- [ ] Security headers are enabled; HSTS is enabled only after HTTPS validation.
- [ ] `.env`, backups, private downloads and logs cannot be requested over HTTP.
- [ ] Avatar uploads reject invalid MIME, dimensions, size and generated-name traversal attempts.
- [ ] Database credentials use the minimum privileges required by NovaNuke.
- [ ] The first administrator uses a unique password and unused accounts are suspended.
- [ ] Administrative permissions are reviewed role by role.
- [ ] Upload limits and allowed MIME/extension pairs are intentionally configured.
- [ ] A real mail transport is configured and recovery links are tested before launch.
- [ ] Database and file backups are encrypted, off-server and restore-tested.
- [ ] Detailed PHP errors are disabled at the server level as well as in NovaNuke.
- [ ] `/admin/system` contains no unresolved production warnings.
- [ ] `php bin/cms security:audit` reports an active Super Administrator, a complete core catalogue, no administrative grants on Guest or Member and no administrative role missing `admin.access`.
- [ ] Maintenance mode was tested without losing login, recovery or administrative access.
- [ ] Generated caches were cleared after deployment.
- [ ] `php bin/cms migrate:status` reports zero pending migrations, zero missing files, zero running/dirty recovery operations and zero module updates.
- [ ] `composer test` and `php bin/cms release:check` both pass on the target PHP version.
- [ ] Production and debug logs redact credential/token patterns.

- [ ] `php bin/cms rc:deployment` has no required failures before public traffic.

- [ ] Router-facing request paths reject encoded slash/backslash/null-byte ambiguity and normalize dot segments before route matching.
- [ ] Download uploads verify extension + MIME; ZIP uploads also require a valid ZIP signature.
- [ ] Image uploads enforce dimensions and a total pixel ceiling before storage.
