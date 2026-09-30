# NovaNuke Notifications 1.2.0

Standalone private in-site notification inbox for NovaNuke.

## Features

- Per-user unread counter and private notification inbox.
- Safe optional links and deduplicated delivery.
- Integrations for private messages, pending comments, friend activity and membership lifecycle events.
- Read-one and read-all progressive actions.
- Maintenance pruning for old read notifications.

All integrations consume typed Core events. Friends, Private Messages, Comments and Memberships continue working when Notifications is disabled or absent.

## Install or update

Upload the ZIP through **Admin → Modules → Install module package**, then install/update and enable Notifications. Do not uninstall with data deletion when preserving the existing inbox; moving the module directory does not remove its database table.

## Verification

```shell
php vendor/bin/phpunit modules/Notifications/tests/NotificationsPackageTest.php
php bin/cms module:check Notifications
php bin/cms module:inspect Notifications
```
