# Updating NovaNuke

## Public upgrade baseline

NovaNuke **0.4.0-beta.1** is the first public release. There are no supported public releases older than Beta 1 and no supported upgrade path from private development snapshots.

For Beta 1, perform a fresh installation using `INSTALLATION.md`.

## Future releases

Starting with the release after Beta 1, this document will list supported source versions and any required migration, module, theme, cache or deployment steps.

When updating a public installation, preserve `.env`, `storage/installed.lock`, user uploads and private storage/backups as documented by the target release. Always create and verify a backup before running migrations.

## NovaLearn 1.0.0 source bundle

This bundle keeps the core at 0.4.0-beta.1. To update an existing Beta 1 site, back up files and database, preserve `.env`, `storage/installed.lock`, `storage/private/` and `public/uploads/`, and replace the application source with this bundle. Install Composer dependencies if `vendor/` is not present. In Admin > Themes, update NovaLearn to 1.0.0; this republishes its assets and retains its settings. The News admin-view fix is included in the full source. No migrations or module version updates are introduced by this bundle. Never overwrite an existing site's runtime data with an archive.
