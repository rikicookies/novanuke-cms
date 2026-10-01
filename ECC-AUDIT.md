# ECC Audit Report: NovaNuke

## Filesystem permissions and shared-hosting compatibility — RESOLVED + VERIFIED

Beta 1 deployment verification found that Windows-created release ZIP entries could
extract source directories without Unix traverse bits, and that public media/theme
outputs used private modes unsuitable for a separate static web server. NovaNuke now
encodes release ZIP directories as `0755` and files as `0644`, provisions public
uploads/assets as `0755` with `0644` files, and keeps runtime/private data at `0700`
directories and `0600` files. Upload validation and Apache executable-upload guards
remain intact. Automated verification covers ZIP external attributes, exclusions,
installer provisioning, `.env` `0600` handling, unit tests, the fresh-install
integration test, and release/theme gates. A fresh Bluehost extraction and upload
retest remains required for real-host acceptance.

Audit scope was read-only. No files, dependencies, Git state, or database state were changed.

## A. Executive Summary

NovaNuke is a custom PHP 8.3 modular CMS inspired by PHP-Nuke. It uses PDO/MySQL, Twig, CommonMark, PHPMailer, Dotenv, a custom router/kernel/container, installable modules, and installable themes.

The core architecture is coherent and security-conscious. Static release, theme, internationalization, syntax, and unit checks are healthy. No confirmed critical or high severity exploitable vulnerability was identified during source review.

The largest confirmed problems are operational:

- The documented integration test runner is missing.
- `phpunit.xml` is absent and ignored, so Composer’s documented PHPUnit commands do not work as written.
- Several documentation links reference missing files.
- One migration combines schema changes with a full-table data update.
- StickyNova's untranslated-strings finding is not applicable because the theme was removed.
- The repository is not a Git checkout at the audited root, so Git health could not be assessed.

Overall assessment: good beta-stage foundation, with release-process and documentation defects that should be fixed before relying on the documented QA workflow.

## B. Architecture Map

### Runtime structure

```text
public/index.php
  ↓
bootstrap/app.php
  ↓
Application::create()
  ↓
Container, configuration, PDO, sessions, Twig, security services
  ↓
Application::boot()
  ├─ core routes
  ├─ installer routes when unlocked
  ├─ enabled module registration and boot
  ├─ active theme registration
  └─ event/listener registration
  ↓
Kernel::handle(Request)
  ├─ maintenance policy
  ├─ private-site policy
  ├─ forced-password-change policy
  ├─ admin access gate
  ├─ module audience gate
  ├─ router match
  └─ controller response
  ↓
Response::send()
```

### Main boundaries

- `app/Core`: framework-like infrastructure and shared services.
- `app/Auth`: authentication, profiles, registration, password reset, sessions.
- `app/Admin`: administrative controllers.
- `app/Installer`: fresh installation and environment provisioning.
- `modules/*`: independently registered feature packages.
- `themes/*`: Twig templates, assets, translations, and public module overrides.
- `database/migrations`: core schema.
- `modules/*/database/migrations`: module-owned schema.
- `resources/views`: core and administrative templates.
- `public`: only web-served assets and the front controller.
- `storage/private`: private avatars, downloads, backups, and other runtime data.

### Request lifecycle

`public/index.php` loads the bootstrap application and captures a request. `Kernel` boots the application, applies maintenance/private-site/password/admin/module guards, matches the normalized route, invokes the route closure, applies security headers and cache policy, and sends the response.

Routes are explicitly registered in `routes/*.php`. Modules register routes while the router tracks the owning module. Failed module registration rolls back owned routes, container bindings, translations, and view paths.

### Extension points

- Module manifests and provider classes.
- Module lifecycle methods.
- Module-owned routes and migrations.
- Event dispatcher and typed event objects.
- Search provider registry.
- Comment/content/media interfaces.
- Theme manifests and Twig namespace overrides.
- Theme settings and published assets.
- Translation namespaces.
- Block and menu managers.

Modules and themes are reasonably independent from Core. Public theme overrides use module namespaces such as `@news/*`; administrative module templates use protected `@admin-*` namespaces. This is a strong boundary.

The main coupling is explicit dependency injection through the application container and shared database access. Some controllers still construct input validators and rate limiters directly inside route definitions, which makes testing and composition more cumbersome.

## C. What Is Working Well

- PHP source is syntactically valid across the audited PHP files.
- Composer manifest is valid.
- PDO uses exceptions, associative fetches, and native prepares in `config/database.php`.
- Core tables use InnoDB, UTF-8, foreign keys, uniqueness constraints, and relevant indexes.
- Authentication uses password hashing, session rotation, authentication-version invalidation, and login history.
- CSRF tokens use 32 random bytes and constant-time comparison.
- Twig autoescaping is enabled in `ViewRenderer.php`.
- User HTML and Markdown pass through `HtmlSanitizer` and CommonMark restrictions.
- Uploads validate extensions, MIME types, size, generated storage names, and storage boundaries.
- Module ZIP extraction rejects traversal paths, duplicate paths, symlinks, oversized archives, and invalid manifests.
- Theme assets reject symlinks and unsupported extensions and use staged publication.
- Public root isolation passed release checks.
- Private storage has an Apache deny rule.
- Security headers and production PHP hardening are present.
- Module route access, admin access, private-site access, and VIP access are centralized.
- Theme/admin namespace isolation is covered by unit tests.
- i18n parity passed for all detected catalogues.
- Release and theme distribution smoke checks passed.

## D. Confirmed Problems

### P1 — Documented integration testing is broken

- Severity: P1
- Confidence: Confirmed
- Evidence: `composer.json:42`, `composer.json:51`
- Status: RESOLVED + VERIFIED
- Verification: `composer test:integration` — 50 tests, 974 assertions, PASS
- The Composer scripts invoke `tests/run-integration.php`, but that file is absent.
- The documentation claims integration tests cover migrations, authentication, permissions, module installation, and uninstall behavior.
- Impact: database migration and installation behavior are not reproducibly validated from the repository.
- Direction: restore the runner and its referenced integration tests, or update the scripts and documentation to match the actual test layout.

### P1 — PHPUnit configuration is missing

- Severity: P1
- Confidence: Confirmed
- Evidence: `composer.json:40`, `.gitignore:28`
- Status: RESOLVED + VERIFIED
- Verification: `composer test` — 33 tests, 107 assertions, PASS
- `composer test` runs `phpunit --testsuite Unit`, but no `phpunit.xml` or `phpunit.xml.dist` exists.
- The file is explicitly ignored by Git.
- Running the documented command produced PHPUnit usage output instead of running tests.
- Direct execution with explicit arguments passed 33 tests and 107 assertions.
- Impact: the supported test command is nonfunctional and CI/release users may believe tests ran when they did not.
- Direction: commit a stable PHPUnit configuration or change Composer scripts to pass explicit bootstrap, suite, and test paths.

### P2 — Migration mixes DDL and data mutation

- Severity: P2
- Confidence: High
- Evidence: `database/migrations/2026_09_10_000018_add_membership_metadata.php`
- Status: ACCEPTED / DEFERRED — NO CURRENT CODE CHANGE REQUIRED
- Conclusion: Finding remains VALID / CONFIRMED. Immediate data-loss risk is LOW; operational risk is MEDIUM only for large `user_entitlements` tables.
- Compatibility: Historical migration `2026_09_10_000018` must not be modified because existing 0.4.0-beta.1 installations may already have executed it.
- Remediation: No repair migration is required at the current expected NovaNuke deployment scale. If future production data volume warrants it, add a new forward-only, bounded, idempotent backfill migration. Future migrations should separate schema changes from large data backfills.
- The migration alters `user_entitlements` and performs broad updates of existing entitlement rows in the same migration.
- Impact: large installations may experience long locks, slow deployment, or difficult recovery.
- Direction: separate schema expansion from data backfill and make large updates batched and observable.

### P2 — Several documentation references are stale

- Severity: P2
- Confidence: Confirmed
- Evidence: `docs/THEMES.md:80`, `docs/THEMES.md:130`, `app/Core/Developer/ModuleScaffolder.php`
- Status: RESOLVED + VERIFIED
- Verification: Created `docs/MODULES.md`, `docs/INTERNATIONALIZATION.md`, and `docs/NOVAMODERN.md`. Existing references to all three documents were validated and now resolve. Documentation was checked against current repository paths, Core classes, manifest keys, commands, and supported behavior. Unsupported or unverified functionality was intentionally not documented.
- At audit time, referenced files were absent:

  - `docs/MODULES.md`
  - `docs/INTERNATIONALIZATION.md`
  - `docs/NOVAMODERN.md`

- Impact: onboarding, module development, and theme development paths lead to dead documentation links.
- Direction: documentation restored and references verified.

### P2 — StickyNova bypasses the translation system

- Severity: P2
- Confidence: Confirmed
- Historical evidence before removal: `themes/stickynova/partials/settings-sheet.twig`, `themes/stickynova/partials/bottom-nav.twig`, `themes/stickynova/module-templates/news/show.twig`
- Visible strings such as `Settings`, `Dark Mode`, `Accent Color`, `Home`, `News`, `Search`, `Account`, `Discussion`, and `Comments` are hardcoded.
- The i18n auditor reported zero matched translation keys for StickyNova.
- Impact: Spanish or future locales will display mixed-language UI.
- Status: NOT APPLICABLE — THEME REMOVED
- Verification: StickyNova was removed from the bundled theme inventory; no translation remediation was applied.

### P2 — Inconsistent formatting reduces maintainability

- Severity: P2
- Confidence: High
- Evidence: `app/Core/Modules/ModuleManager.php:246`, `app/Core/I18n/Translator.php`, several compact module controllers.
- Important lifecycle and rollback helpers are compressed into dense one-line methods.
- Impact: review, debugging, static analysis, and future security changes become harder.
- Direction: format high-risk Core and module lifecycle code consistently and prioritize security-sensitive paths.

## E. Security Findings

### Confirmed vulnerabilities

No confirmed critical or high severity vulnerability was established from the source review.

The following controls were verified:

- Native PDO prepares are configured.
- Route paths reject encoded slash, backslash, and null-byte ambiguity.
- Redirect helpers reject external URLs unless explicitly using the constrained external redirect method.
- CSRF validation exists across reviewed state-changing flows.
- Admin authorization is enforced centrally and again in controllers.
- Session cookies are HTTP-only and support Secure/SameSite configuration.
- Password resets and email verification tokens are hashed, expiring, and single-use.
- User HTML/Markdown is sanitized before being wrapped as Twig markup.
- Uploads use generated filenames and storage boundary checks.
- Module package extraction includes traversal and symlink defenses.
- Private storage and upload directories contain server protection rules.
- Error responses redact internal details outside debug mode.

### Deployment security concern

- Severity: P1 if deployed unchanged
- Confidence: High
- The local `.env` is an installed development-style environment with non-production URL and cookie settings.
- Production checks correctly require HTTPS, secure cookies, debug disabled, protected `.env`, and a real mail transport.
- This is not evidence that production is vulnerable; it means deployment must pass the existing production readiness checks before exposure.

### Areas requiring runtime verification

The following could not be proven without a database-backed environment:

- Actual role and permission records.
- Installed module audience state.
- Migration status and recovery state.
- Membership entitlement integrity.
- Password/session behavior against real MySQL.
- Installer behavior against an empty database.
- Backup restore behavior.

Those checks were intentionally not run because the available documented integration path is missing and the integration workflow creates/drops databases.

## F. Testing / QA Findings

### Actual repository state

- 33 unit tests passed.
- 107 assertions passed.
- Two PHPUnit deprecations were reported.
- PHP syntax lint passed across the audited PHP files.
- `composer validate --no-check-publish` passed.
- i18n catalogue audit passed.
- Theme distribution check passed.
- Release checklist passed.
- Release smoke check passed.
- All 18 module diagnostic checks passed, with documentation/catalogue warnings only.
- Integration tests were not run because `tests/run-integration.php` is absent and the documented workflow mutates databases.
- The Composer PHPUnit command is broken because the PHPUnit configuration is absent.

### Coverage strengths

Unit tests cover:

- Theme/module view boundaries.
- Admin namespace isolation.
- Contact form contracts.
- Theme override behavior.
- Several module package contracts.
- Selected content and permission behavior.

### Coverage gaps

- No executable integration runner is present.
- No verified fresh-install integration execution.
- No verified upgrade execution.
- No real MySQL migration run from this checkout.
- No runtime browser smoke test was run.
- No automated accessibility test was found.
- A committed CI workflow exists at `.github/workflows/ci.yml` and is verified by successful GitHub Actions execution on 2026-09-30.

## G. Module / Theme Findings

### Modules

18 bundled modules were detected:

Comments, DemoContent, Downloads, Friends, Landing, Media, News, Notifications, Pages, Polls, PrivateMessages, Quotes, Search, SEO, Statistics, WebLinks, Welcome, and Wiki.

The module system supports:

- JSON manifests.
- Provider classes.
- Version and PHP compatibility checks.
- Dependencies.
- Permissions.
- Route ownership.
- Event listeners.
- Module translations.
- Module migrations.
- Enable/disable state.
- Audience restrictions.
- Atomic ZIP installation.

The module diagnostics passed for all modules.

Warnings were reported for missing README files, missing catalogues in modules without visible translation assets, and modules without migration directories where that is valid.

### Themes

Two supported bundled themes remain:

NovaLearn and NovaModern.

The theme system supports:

- Manifest validation.
- Layout declarations.
- Settings.
- Asset publication.
- Staged asset replacement.
- Translation namespaces.
- Public module template overrides.
- Protected administrative namespaces.
- Active-theme lifecycle rules.

The strongest architectural boundary is the separation between public module overrides and admin module templates.

Theme concerns:

- Legacy bundled themes were removed after adding a compatibility fallback for existing installations with a missing active theme. The fallback persists NovaModern only when its manifest and installed record are both available.
- Several themes duplicate navigation and layout markup.
- Some themes use icon-only controls and rely on adjacent text or attributes inconsistently.
- Accessibility was reviewed statically only; no browser or screen-reader validation was performed.

## H. Install / Upgrade Findings

The installer includes:

- Requirement checks.
- Installation lock handling.
- Empty-database validation.
- Environment file writing.
- First administrator creation.
- Core migration execution.
- Storage provisioning.
- Failure cleanup.

The release checks confirm public-root isolation and private storage protection.

Upgrade support is intentionally limited:

- `0.4.0-beta.1` is the first supported public upgrade baseline.
- Earlier private development snapshots are not supported upgrade sources.
- The update documentation explicitly requires preserving `.env`, installation lock, uploads, and private storage.

Risks:

- The actual fresh-install integration path is missing.
- Migration behavior has not been validated against a real database from this checkout.
- Broad data updates inside migrations may be slow on large installations.
- Backup and restore claims remain unverified in this audit.

## I. Technical Debt

- Dense one-line PHP in several Core and module classes.
- Direct construction of dependencies in route files.
- Repeated controller guard patterns across modules.
- Multiple generations of module compatibility versions in manifests.
- Legacy/deprecated compatibility markers remain in code, including the deprecated Pages event type.
- Theme markup and navigation are duplicated across bundled themes.
- Some modules have weaker documentation than the Core.
- Runtime Twig cache exists locally, though it is correctly ignored.
- A repository-level CI definition exists at `.github/workflows/ci.yml`; GitHub-hosted execution is verified by run `36776262772` on 2026-09-30.
- Test configuration is treated as local ignored state rather than committed project configuration.

## J. Documentation Mismatches

- `composer test` is documented as the unit test command, but its PHPUnit suite requires missing configuration.
- `composer test:integration` references a missing runner.
- `composer test:checkpoint` references the same missing integration runner.
- `docs/TESTING.md` describes integration coverage that cannot currently be executed from this checkout.
- `docs/THEMES.md` references missing `INTERNATIONALIZATION.md` and `NOVAMODERN.md`.
- Module scaffolding references missing `docs/MODULES.md`.
- README release instructions imply the documented test checkpoint is available, but it is currently incomplete.
- The project root is an installed site with `.env` and `storage/installed.lock`, while several installation instructions describe a fresh source tree.

## Prioritized Remediation Backlog

### P0 — Critical / security / data loss

No confirmed P0 issue found.

## Verified Remediation Status

- P2 — Migration mixes DDL and data mutation: ACCEPTED / DEFERRED — NO CURRENT CODE CHANGE REQUIRED. The finding remains VALID / CONFIRMED; no repair migration is required at the current expected deployment scale.

- P1 — Documented integration testing is broken: RESOLVED + VERIFIED. `composer test:integration` passed with 50 tests and 974 assertions.
- P1 — PHPUnit configuration is missing: RESOLVED + VERIFIED. `composer test` passed with 33 tests and 107 assertions.
- P2 — Module installation failure recovery and theme asset rollback coverage: RESOLVED + VERIFIED. `ThemeAssetPublisherRollbackTest` passed with 3 tests and 14 assertions; `ModuleInstallationRecoveryTest` passed with 1 test and 9 assertions; full unit and integration suites also passed.
- P1 — Fresh-install and migration validation: RESOLVED + VERIFIED. `InstallerFreshInstallIntegrationTest` exercises the real `InstallerService` against an isolated empty MySQL database and temporary application root; focused validation passed with 1 test and 33 assertions, and the full integration suite passed with 52 tests and 1016 assertions.
- P1 — Missing committed CI workflow: RESOLVED + VERIFIED. GitHub repository `rikicookies/novanuke-cms` run `36776262772` succeeded on 2026-09-30 at commit `315b911028d281e6d959421c91aee868bd9c437b`; both the PHP 8.3 quality-gates job and PHP 8.3 MySQL-integration job passed. The workflow installs locked Composer dependencies, runs syntax/unit/i18n/release/smoke/theme gates, and exercises the disposable MySQL integration contract.

### P1 — Before next release

1. Restore or replace `tests/run-integration.php` (RESOLVED + VERIFIED).
2. Commit PHPUnit configuration and make `composer test` execute the intended unit suite (RESOLVED + VERIFIED).
3. Add CI that runs syntax lint, unit tests, release checks, i18n checks, and safe static checks (RESOLVED + VERIFIED; GitHub Actions run `36776262772` passed on 2026-09-30).
4. Execute fresh-install and migration tests against disposable MySQL/MariaDB databases (RESOLVED + VERIFIED).
5. Require production readiness checks before deployment, including HTTPS, secure cookies, debug disabled, mail configuration, and migration status.

### P2 — Important maintainability and quality

1. Split membership metadata schema changes from data backfill in future migrations; ACCEPTED / DEFERRED — NO CURRENT CODE CHANGE REQUIRED for the historical migration.
2. Repair stale documentation links and restore module/internationalization/theme documentation (RESOLVED + VERIFIED).
3. StickyNova translation remediation — NOT APPLICABLE — THEME REMOVED.
4. Reformat dense Core/module lifecycle code.
5. Add browser accessibility checks for keyboard focus, labels, icon buttons, dialogs, contrast, and responsive reflow.
6. Add module README files and catalogue declarations where appropriate.
7. Module installation failure recovery and theme asset rollback coverage: RESOLVED + VERIFIED. Focused regression tests cover staged theme-asset cleanup, live-asset restoration after swap failure, backup preservation when restoration fails, later module migration interruption after an earlier migration, explicit recovery/retry, and the module remaining uninstalled until finalization succeeds.

### P3 — Optional polish

1. Reduce duplicated navigation and layout markup among themes.
2. Standardize controller guard and dependency construction patterns.
3. Add static analysis and formatting checks.
4. Add a committed release checklist workflow.
5. Add explicit documentation for database engine/version compatibility and migration lock expectations.

## ECC Usage Report

### ECC skills used

- `codebase-onboarding` — reconnaissance, architecture mapping, entry-point and request-lifecycle analysis.
- `security-review` — authentication, authorization, CSRF, XSS, upload, redirect, secret, and session review.
- `mysql-patterns` — schema, indexing, transaction, PDO, and migration review.
- `backend-patterns` — transferable service-boundary, validation, authorization, error-handling, and database review.
- `coding-standards` — maintainability, duplication, dense code, naming, and technical-debt review.
- `repo-scan` — repository structure and artifact inspection.
- `git-workflow` — Git status/history/ignore checks; the root was not a Git repository.
- `accessibility` — static theme and template accessibility review.
- `e2e-testing` — test infrastructure and browser-QA review.
- `database-migrations` — migration safety and schema/data separation review.
- `agent-architecture-audit` — evaluated and intentionally not applied because NovaNuke is not an agent or LLM application.
- `security-scan` — evaluated and intentionally not applied because it targets Claude configuration directories, which this project does not contain.

### ECC agents used

No ECC agent runner was available in the callable tools for this session. The audit therefore used the applicable ECC skills directly rather than claiming agent execution.

### Capabilities intentionally not used

- Agent architecture and agent-evaluation capabilities: not relevant to a PHP CMS.
- Browser automation: no browser runner was available, and starting a server would add runtime state outside the requested read-only static audit.
- Integration test execution: intentionally skipped because the documented runner is missing and the intended tests create/drop databases.
- Install, migration, backup, restore, maintenance, and site checks that mutate or depend on persistent runtime state: intentionally skipped.
## PHPUnit doc-comment metadata deprecations

RESOLVED + VERIFIED
