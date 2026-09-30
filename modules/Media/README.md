# NovaNuke Media Library 1.2.0

Standalone reusable image library for NovaNuke.

## Features

- Validated JPEG, PNG and WebP uploads.
- Managed titles and alternative text.
- Safe storage below `public/uploads/media/YYYY/MM`.
- Usage checks prevent deletion while News, Pages or another module references an image.
- Core `MediaLibraryInterface` integration lets editors discover Media when enabled without requiring it.

## Install or update

Upload the ZIP through **Admin → Modules → Install module package**, then install/update and enable Media. Assign `media.manage` to the appropriate administrator roles.

When updating an existing site, do not uninstall with data deletion. Database records remain in `media_files`, and uploaded images remain under `public/uploads/media` when the old module directory is moved.

## Verification

```shell
php vendor/bin/phpunit modules/Media/tests/MediaPackageTest.php
php bin/cms module:check Media
php bin/cms module:inspect Media
```
