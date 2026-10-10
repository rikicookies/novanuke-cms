# Backups and restoration

## Create a matched backup set

For release/upgrade recovery, create the database SQL and file archive together so both carry the same durable backup-set identifier:

```bash
php bin/cms backup:create
```

`backup:create` is the preferred workflow for RC/stable acceptance. It stages SQL, files and `manifest.json` under an `.incomplete-set-...` directory, verifies both artifacts, and only then atomically publishes `storage/private/backups/set-.../`. The external manifest is the commit record: a directory without a valid complete manifest is not a recovery set.

Newly-created sets also contain an additive `recovery_inventory` object with schema version 2. The top-level backup `format` remains version 1 for compatibility. The inventory records the Core/PHP/database runtime, required extension availability, Composer lock digest, installed module/theme metadata, active theme, migration summary, artifact names/sizes/SHA-256 values, included and excluded relative roots, document-root requirements and manual migration inputs. It never records `.env` values, credentials, APP_KEY, passphrases, absolute private paths or exception traces.

The inventory is metadata, not a signature. A verified SHA-256 or authenticated encrypted artifact proves integrity/authentication of that artifact; it does not prove external provenance if an attacker replaces the complete set. No current manifest is cryptographically signed. `php bin/cms backup:readiness [MANIFEST] [--passphrase-file=PATH]` performs a non-destructive compatibility/readiness evaluation after normal verification. Legacy manifests remain verifiable but are reported as legacy and never as fully portable.

The older individual commands remain available for operational use:

```bash
php bin/cms backup:database
php bin/cms backup:files
```

Individual commands generate independent set IDs and therefore do **not** form a release-verified recovery pair by timestamp alone.

NovaNuke creates the SQL dump under `storage/private/backups/` with owner-only file permissions. For InnoDB tables it uses `REPEATABLE READ` plus `START TRANSACTION WITH CONSISTENT SNAPSHOT`, avoiding any dependency on shell access or `mysqldump`. The backup directory itself must be a real directory and not a symbolic link. The command does not accept a browser-supplied path, and incomplete `.part` files are removed after failure.

The SQL file contains sensitive data including password hashes, reset tokens, private messages and site content. Download it through SSH/SFTP or the host file manager, encrypt it, store it off-server and delete old server copies according to a retention policy. Never place it under `public/` or commit it.

Database backup does not include:

- `.env` (save it separately through a secure channel);
- local download files, avatars, custom modules, themes or uploaded public media.

The file half of a matched set is created automatically by `backup:create`. `backup:files` remains available when a standalone archive is intentionally needed. It creates a private TAR archive containing `modules/`, `themes/`, `public/uploads/`, `storage/private/avatars/` and `storage/private/downloads/`. It never follows symbolic links and does not include `.env`, database data, logs, sessions, caches or other backups. `NOVANUKE-BACKUP.json` inside the archive records each path, byte size and SHA-256 digest. The command also prints the archive SHA-256 so it can be checked after moving the file off-server.

The archive can contain executable module code and private user files. Treat it as sensitive, keep it outside `public/`, encrypt off-server copies and restore only code from a trusted backup. Save `.env` separately through a secure channel.

### Portability boundaries

The file archive includes `modules/`, `themes/`, `public/uploads/`, `storage/private/avatars/`, `storage/private/downloads/` and `storage/private/wiki/`. It excludes the application Core, `vendor/`, `.env`, `storage/installed.lock`, caches, logs, sessions, generated `public/assets/` and the backup directory itself. Obtain a compatible Core release, dependencies and runtime secrets separately. The SQL artifact contains the database tables and rows, including users, roles, permissions, settings and module content; it must only be imported into an empty compatible database during recovery.

For hosting migration, prepare the new database and `.env`, point the document root to `public/`, install compatible dependencies, restore only into empty staging locations, run migration/release checks, configure DNS/TLS/mail/cron and invalidate old sessions before switching traffic. A verified backup is not proof that a disposable recovery has been completed, and the current readiness result remains conservative until such evidence exists.

## Verify the backup pair

Immediately after creating the set, run:

```bash
php bin/cms backup:verify
```

To verify a copied set explicitly, pass its manifest path:

```bash
php bin/cms backup:verify storage/private/backups/set-.../manifest.json
```

The versioned manifest records the set ID, UTC timestamps, CMS/PHP/database metadata, dump strategy, included/excluded components, warnings, filenames, exact sizes and SHA-256 hashes. It never records database credentials or the DSN.

The database, files and matched-pair checks must all report `PASS`. On POSIX hosts verification also rejects backup files that are accessible to group/other users. NovaNuke verifies the latest SQL envelope and fingerprint, every TAR header and manifest size/hash, safe archive paths and a complete terminator without executing or extracting anything. The SQL header and TAR manifest must contain the **same backup-set ID**. Timestamp proximity is no longer accepted as proof that two independently-created files belong together. Record the set ID, filenames and displayed SHA-256 fingerprints with the off-server copy.

`php bin/cms upgrade:check --from=VERSION` repeats these integrity checks and additionally requires backups created within the last 24 hours. Neither command proves authenticity against an attacker who can replace both a backup and its recorded fingerprint.

## Restore test

Restoration is deliberately not exposed through the web panel. `backup:restore-check` can perform a real SQL import when you provide credentials for an **empty disposable MySQL database**:

```dotenv
NOVANUKE_BACKUP_VERIFY_DSN="mysql:host=127.0.0.1;port=3306;dbname=novanuke_restore_check;charset=utf8mb4"
NOVANUKE_BACKUP_VERIFY_USERNAME="..."
NOVANUKE_BACKUP_VERIFY_PASSWORD="..."
```

NovaNuke never creates or selects a database for this check. It refuses a non-empty target, imports the generated SQL, verifies restored tables and migration history, then removes the imported tables. This is compatible with shared hosting where the operator creates a disposable database in cPanel first.

If those credentials are unavailable, `backup:restore-check` and `rc:deployment` report **MANUAL REQUIRED / NOT VERIFIED** for SQL restore. They must not report PASS merely because the file checksum/envelope is valid.

For manual acceptance, create an empty database, import the SQL file with MySQL/MariaDB tools or phpMyAdmin, extract the matching file archive over a clean copy of the same NovaNuke release, then configure `.env`. Compare restored files with the manifest before exposing the site. Test restoration periodically on a non-production system. A backup that has never been restored is not yet proven usable.

The built-in exporter is intentionally portable for shared hosting. Large sites may prefer the provider's snapshot system or `mysqldump` because those tools scale better and can coordinate database locking options.

## Portable export bundle and Admin download

A verified manifest-backed set can be exported without changing its SQL/TAR artifacts:

```bash
php bin/cms backup:export SET_ID --destination=/protected/path/novanuke-backup-SET_ID.tar
php bin/cms backup:export-verify /protected/path/novanuke-backup-SET_ID.tar
```

The bundle is a streaming TAR containing `export.json`, the original `manifest.json`, the original database artifact and the original files artifact. It preserves ciphertext byte-for-byte for encrypted sets. `backup:export-verify` checks the descriptor, safe relative names, byte sizes, SHA-256 values and the embedded manifest/artifact relationship without importing or extracting into the application. For plaintext artifacts it performs the existing SQL/TAR verification. For encrypted artifacts it verifies the ciphertext and envelope metadata; authenticated decryption still requires the original external passphrase.

Super-administrators with `backup.manage` can use the Export action in Admin Backup Manager. The action is POST-only, CSRF-protected, resolves only a strict server-side backup-set ID, verifies the set before streaming and removes its private temporary bundle after the response. No public URL, signed link, browser passphrase or arbitrary filesystem path is accepted. Plaintext exports contain sensitive database and file data; encrypted exports preserve ciphertext but do not recover the passphrase.

The export does not include `.env`, vendor dependencies, Core source, generated caches/assets, runtime secrets, logs or sessions beyond whatever was already present in the verified backup artifacts. Store the resulting file outside the host, preferably encrypted at rest, and verify it again after transfer. Large sets remain subject to PHP request, disk and hosting limits; CLI/SFTP or provider snapshots may be more appropriate for very large installations. This workflow does not perform live restore.
