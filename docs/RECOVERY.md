# Recovery

## Site returns an internal error

1. Leave `APP_DEBUG=false` on a public server.
2. Copy the reference shown on the error page.
3. Find that reference in `storage/logs/novanuke.log`.
4. Check PHP version/extensions, database availability, writable storage and recent file changes.
5. Do not paste `.env`, SMTP conversations, reset links or full production logs into a public ticket.

NovaNuke redacts common credential, authorization and token patterns from exception logs, but logs must still be treated as private.

## Interrupted database migration

NovaNuke records every Core and module schema operation in `migration_operations` before executing DDL. A `running` row means the process ended without reporting a result; a `dirty` row means NovaNuke caught an error after the attempt began. `completed` rows are audit evidence and require no operator action. The existing `migrations` and `module_migrations` tables remain the authoritative history.

Do not delete or edit any of these rows. Keep maintenance mode enabled and inspect the durable state:

```bash
php bin/cms migrate:status
```

Normal `migrate`, module install/update, and module uninstall operations refuse to start while any `running` or `dirty` operation exists. Recovery is deliberately explicit so a later pending migration can never run ahead of an interrupted schema change.

After correcting the reported cause, reconcile an interrupted Core migration:

```bash
php bin/cms migrate:recover
```

For a module install, update, or data-deleting uninstall, use its slug:

```bash
php bin/cms migrate:recover --module=quotes
```

Recovery verifies the migration file's SHA-256 against the interrupted attempt. A changed file is rejected. Bundled migrations inspect their schema/data postconditions: if all effects are already present, NovaNuke records the existing history without repeating the DDL; otherwise the idempotent migration resumes and verifies its final state. Module rollback recovery executes in reverse history order.

If recovery says an interrupted migration is legacy/non-recoverable, do not retry it or edit the ledger. Restore the matched pre-update backup, or deploy a reviewed recoverable version of that exact migration. A database account must support MySQL/MariaDB `GET_LOCK`; failure to acquire the advisory lock is a hard stop protecting against concurrent schema runners.

## Locked out during maintenance

Login, password recovery and administrative routes remain available. Sign in through `/login`, open `/admin/settings` and disable maintenance. Do not delete the installation lock.

## Lost administrator access

Use normal password recovery after confirming SMTP delivery. If email is unavailable, restore access through a controlled database recovery performed by the server owner; never add a public bypass route or weaken core authorization.

## Damaged update

1. Keep maintenance enabled.
2. Restore the exact prior application files.
3. Restore the matching database backup when migrations changed schema/data.
4. Preserve private downloads and `.env`.
5. Run `composer install`, `php bin/cms cache:clear` and `php bin/cms release:check`.

## Installer unexpectedly appears

Stop and restore `storage/installed.lock` from a trusted backup. Verify `.env` and the database are intact. Do not submit the installer against an existing production database.


## Verified private-file restore

Never extract a NovaNuke backup directly over a live application tree.

First verify the latest matched backup set and prove both restore paths where the environment allows it:

```bash
php bin/cms backup:verify
php bin/cms backup:restore-check
```

`backup:restore-check` always uses a disposable temporary directory for files. When `NOVANUKE_BACKUP_VERIFY_DSN` credentials point to an empty disposable MySQL database, it also performs a real SQL import, verifies tables/migration history, and cleans the imported tables. It never overwrites the live site database.

For manifest-backed sets, keep the entire `set-...` directory together. Verify `manifest.json` before any manual import or extraction. A `.incomplete-set-...` directory is staging debris, not a valid backup, and can be removed only after confirming no backup process is running. If a manual restore fails midway, keep the original site offline, preserve the verified set, recreate an empty destination database/directory, and restart from verification instead of continuing from an unknown partial state.

Restore the file archive into a new empty disposable directory:

```bash
php bin/cms backup:restore-files --archive=/protected/path/novanuke-files-....tar --destination=/new/empty/restore
```

The restore command verifies the TAR manifest/checksums before extraction, rejects a non-empty destination and has no overwrite/force mode.

NovaNuke does not create a restore database automatically. Create/select an empty disposable database with the database server's normal administration tooling. Either configure the three `NOVANUKE_BACKUP_VERIFY_*` values and run `backup:restore-check`, or import the verified SQL manually and record the result. If automated credentials are absent, release acceptance remains `MANUAL REQUIRED / NOT VERIFIED` until that manual evidence exists.

After recovery, run `php bin/cms migrate:status` and installed-site checks using the application release matching the backup before exposing the restored site.

## Portable recovery inventory

Manifest-backed sets created by the current release include `recovery_inventory.schema_version = 2`. It is an additive inventory associated with the existing SQL/TAR pair, not a replacement archive format. It identifies the backup-set ID, runtime and database compatibility, Composer lock digest, detected module/theme state, migration summary, artifact hashes/sizes, safe relative included/excluded roots and operator-supplied migration inputs.

Run the non-destructive readiness evaluation after verifying the set:

```bash
php bin/cms backup:readiness storage/private/backups/set-.../manifest.json
```

For encrypted sets, add `--passphrase-file=/protected/operator-secret`. The passphrase is never written to the inventory. The command can report version, extension, module, theme, migration, snapshot or manual-configuration issues; it exits non-zero unless the conservative conditions are satisfied. It does not import SQL, extract files or change the database. It also requires an explicit recovery-test result, so it will not claim that recovery has been tested merely because hashes verify.

Older manifests without `recovery_inventory` remain supported by `backup:verify`, `backup:status` and `backup:restore-check`. They are legacy manifests with less portability metadata and must be treated as requiring manual inventory. No old manifest is rewritten automatically.

The inventory deliberately excludes `.env`, credentials, APP_KEY, passphrases, absolute server paths, sessions, logs and exception traces. The operator must separately provide a compatible Core release/vendor tree, destination database credentials, new runtime secrets, document-root/rewrite setup, site URL/DNS/TLS/mail/cron configuration and any encryption passphrase. `manifest_signed=false` means external authenticity has not been established; integrity and provenance are separate claims.
