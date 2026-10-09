# Testing NovaNuke

NovaNuke separates fast unit tests from MySQL/MariaDB integration tests. Neither suite reads the normal `.env` file or targets the configured `novanuke` database.

## Unit tests

```bash
composer test
```

This runs only the `Unit` suite and requires no MySQL database. Some unit
tests use `sqlite::memory:`; development and CI PHP installations therefore
need `pdo_sqlite`. This is a test-only extension and is not a NovaNuke
production database requirement.

## Integration tests on Laragon

Copy the testing example once:

```bash
copy .env.testing.example .env.testing
```

The example uses Laragon's common MySQL connection: `127.0.0.1:3306`, user
`root` and an empty password. Adjust only `.env.testing` when your local test
server credentials differ. The real file is ignored by Git and release
packages. The test account must be able to create and drop databases, and must
not be a production account.

Run:

```bash
composer test:integration
```

The runner loads `.env.testing`, enables the integration flag, and invokes the
dedicated PHPUnit `Integration` suite from `phpunit.xml.dist`. It does not run
the Unit suite or module package tests. Every integration test creates a new
database named `novanuke_test_` followed by 16 random hexadecimal characters,
executes the core migrations and removes that exact temporary database
afterward. The harness refuses names outside that pattern and refuses to use
the configured application database name as an integration database.

The database account needs temporary `CREATE DATABASE` and `DROP DATABASE` privileges. This is appropriate for local Laragon development but commonly unavailable—and not recommended—on production shared hosting.

Run both suites locally with:

```bash
composer test:all
```

## Initial integration coverage

- all core migrations against real MySQL/MariaDB;
- password login, session identity, login history and `user.logged_in` dispatch;
- Administrator permissions including denial of `roles.manage`;
- hashed, expiring, single-use password-reset tokens and password version invalidation;
- cancellation of pending email-change tokens after password reset;
- fixture module install, permission registration, activation and route boot;
- non-destructive uninstall preserving module data and migration history;
- destructive uninstall removing only module-owned schema.

SMTP remains a separate manual smoke test because reliable delivery depends on the real DNS, mailbox and hosting provider.

## Failure cleanup

If PHP or MySQL terminates abruptly, inspect local databases whose names match `novanuke_test_[a-f0-9]{16}`. Confirm the exact generated pattern before manually dropping an abandoned test database. Never automate wildcard deletion and never alter the normal `novanuke` database.


## Development checkpoint validation

During larger development batches, do not create a release for every small correction. Accumulate related work and validate it together.

Use:

```bash
composer test:checkpoint
```

The checkpoint runs the normal PHPUnit suite followed by the isolated integration suite. Membership-only investigation can still use `composer test:membership`.

A release checkpoint should be packaged only after the accumulated batch is internally consistent and ready for validation in the target Laragon/shared-hosting environment.


Before packaging a checkpoint for target-environment QA, also run:

```bash
composer check:release
```

This groups install requirements, production readiness, and Membership
integrity checks. It is intentionally separate from `test:checkpoint` because
these checks inspect the current installation/environment.

## Membership validation

```bash
composer test:membership
```

This is the Composer entry point for the existing read-only
`php bin/cms membership:check` health check. It validates membership schema and
data integrity on an already-installed site; it is not a PHPUnit unit suite
and it does not create, migrate, or repair data. Run it only with the intended
development/staging `.env` database, never against production or an unrelated
application database. Membership behavior itself has no dedicated PHPUnit
suite in the current repository, so this command must not be described as
full membership behavior coverage.

On Windows/Laragon, use PowerShell or Command Prompt from the repository after
Composer installation. On Linux/CI, install the declared PHP extensions plus
`pdo_sqlite` for unit tests; CI integration uses the isolated MySQL service and
the `NOVANUKE_TEST_DB_*` variables shown in `.env.testing.example`.


## Installation versus installed-site checks

These commands intentionally represent different lifecycle states:

```bash
composer check:install
```

Use only before installation. It expects no `.env` and no `storage/installed.lock`.

```bash
composer check:site
```

Use for an existing development/staging installation. It expects `.env`, a valid installation lock, the recorded Core version to match the running code, no pending/missing migrations, no pending installed-module update, writable runtime directories, and healthy Membership/Payment state.

```bash
composer check:release
```

Use for a site intended for production. It includes distribution smoke checks, installed-site health, production configuration, Membership integrity and optional Payment integrity. A local development site can legitimately pass `check:site` while failing `check:release` because production settings such as HTTPS, `APP_ENV=production`, secure cookies or SMTP are not enabled.
