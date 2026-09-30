# NovaNuke Wiki 2.1.0

Standalone Markdown wiki for NovaNuke 0.4.0-rc.4 and later compatible releases.

## Install or update

Upload the ZIP in **Admin → Modules → Install module package**. When updating an existing installation, disable Wiki, move the old `modules/Wiki` directory outside the project, upload this package, then choose **Update** and **Enable**.

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
php vendor/bin/phpunit modules/Wiki/tests/WikiPackageTest.php
php bin/cms module:check Wiki
php bin/cms module:inspect Wiki
```

Complete archive export additionally requires PHP's ZIP extension.
