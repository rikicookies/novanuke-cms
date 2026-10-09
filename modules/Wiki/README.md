# NovaNuke Wiki 2.4.0

Standalone Markdown wiki for NovaNuke 0.4.0-beta.1 and later compatible releases.

## Install or update

Upload the ZIP in **Admin → Modules → Install module package**. For an existing installation, the package flow validates the newer version, stages it, preserves the previous source in private recovery storage, and performs the normal update lifecycle. Keep a site and database backup before updating; do not uninstall Wiki or manually replace its directory. Review migration status after the update and enable the module only after the update result is confirmed.

Do not uninstall the module when preserving existing pages, revisions, comments, or attachments. Wiki records remain in the database and private attachments remain under `storage/private/wiki`.

## Features

- Namespaced Markdown pages, drafts, publishing and revision history.
- Backlinks, missing-link discovery, search, recent pages and a Wiki map.
- Safe Markdown import/export, folder import and complete ZIP archives.
- Private attachments with inline-image support.
- Optional Comments, Search and sitemap integration through core contracts.
- Self-contained editor, folder-import and bulk-action JavaScript assets.

## Verification

```shell
php vendor/bin/phpunit modules/Wiki/Tests/WikiPackageTest.php
php bin/cms module:check Wiki
php bin/cms module:inspect Wiki
```

Complete archive export additionally requires PHP's ZIP extension.

## 2.4.0

Adds namespace `start` landing pages, polished Wiki create/edit forms, and safe module-owned namespace rename/move management with collision preflight and transactional updates.

Completed namespace moves preserve historical Wiki URLs through persistent aliases. Restoring a revision changes historical content without moving the page to an old path.
