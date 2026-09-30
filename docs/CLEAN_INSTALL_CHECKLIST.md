# Clean installation checklist

This checklist is the clean-install acceptance baseline for the current NovaNuke beta/Release Candidate line. Run it against a disposable database and a new application directory. Never point these steps at a production database.

## Laragon baseline

1. Create an empty directory such as `C:\dev\www\novanuke-clean-test`.
2. Extract the release into that directory.
3. Preserve no `.env` or `storage/installed.lock` from another installation.
4. Run `composer install`, followed by `php bin/cms install:check`.
5. Create an empty MySQL/MariaDB database with `utf8mb4`. NovaNuke must refuse it if even one table already exists.
6. Configure the Laragon virtual host document root as the release's `public\` directory.
7. Open the site and complete `/install` with a new Super Administrator.
8. Confirm the installer becomes unavailable after completion.

No database table, `.env` value or lock file should require manual editing.

## Core acceptance

- Sign in and out with the new Super Administrator.
- Open the Admin dashboard, Settings, Users, Roles, Modules, Themes, Logs and System Information.
- Confirm production secrets are absent from rendered pages and logs.
- Confirm NovaModern and NovaLearn can each be activated without a server error.
- Run `php bin/cms migrate:status` and confirm no Core migration or running/dirty recovery operation is pending.
- Run `php bin/cms release:check` and record any warning rather than bypassing it.

## Module acceptance

Run `php bin/cms module:check` against each bundled module and resolve every FAIL. Then install and enable each bundled module from Admin. Quotes is tested separately by uploading its official standalone package, installing and enabling it, and verifying `/quotes` plus **Admin → Quotes**. Afterward:

```bat
composer test
composer test:integration
php bin/cms migrate:status
```

The integration suite creates and destroys its own temporary database. Configure `.env.testing` as documented in [TESTING.md](TESTING.md); never reuse the site database.

Confirm that every installed module reports its current bundled version, has no pending module migration and can be disabled and enabled again without losing its records.

## Demo Content acceptance

Install and enable the desired content modules before installing **Demo Content**. Then open **Admin → System → Demo content**, confirm the warning and install `novatech-community-v1`.

Expected fixed dataset definitions:

| Type | Expected |
| --- | ---: |
| Fictional users | 12 |
| VIP examples | 3 |
| News | 10 |
| Pages | 8 |
| Downloads | 10 |
| Web Links | 10 |
| Comments | 36 |
| Polls | 5 |
| Private conversations | 12 |

Counts for optional module records appear only when the corresponding module was active at installation time. Friends additionally creates seven accepted relationships, one pending request and one block example.

Check the site as a guest, ordinary member, active VIP and expired VIP. Verify Public, Member and VIP visibility on News, Pages, Downloads and Web Links. Open threaded comments, reactions, polls, user profiles, friends, notifications and private messages.

Attempting to install the same dataset a second time must be rejected without duplicating or replacing content.

## Repeatability check

Repeat the entire process with a second empty database and a second empty application directory. The same steps must produce the same module inventory and demo counts. Record any additional manual action as a defect; undocumented local knowledge is not part of a valid installation procedure.

## Failure recovery

- Preserve the first error message and relevant sanitized log entry.
- Do not edit migration rows or application tables manually to force completion.
- For an installer failure, remove the disposable database and start again after correcting the cause.
- For a Demo Content failure that reports successful cleanup, correct the validation problem and retry. If it reports cleanup failure, preserve the database for diagnosis rather than deleting individual rows.
- Never display or share `.env`, password hashes, reset tokens, session IDs or SMTP credentials in a bug report.

## Recorded result

Record the NovaNuke version, PHP version, database/version, web server, clean-install result, module test result, Demo Content result and every warning. A Release Candidate is accepted only after this checklist succeeds without undocumented intervention; repeat the clean-install pass when validating final packaging.

## Distribution smoke

Before beginning a fresh-install test from a packaged release, run `php bin/cms release:smoke`. The package must pass before Composer/application/database bootstrap is considered part of the test.


## Automated installer regression

`composer test:integration` includes `InstallerFreshInstallIntegrationTest`. It creates a random disposable database and temporary application root, runs the real `InstallerService`, verifies the current Core version/Super Administrator/storage structure, and separately proves that a non-empty database is refused without deleting pre-existing data.

This automated regression complements, but does not replace, the browser-based fresh-install acceptance above.
