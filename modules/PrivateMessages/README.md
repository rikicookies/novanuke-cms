# NovaNuke Private Messages 1.3.0

Standalone private-conversation module for NovaNuke.

## Features

- Private inbox, sent messages, conversations and replies.
- Markdown or sanitized HTML message bodies.
- User blocking, abuse reports and moderator workflow.
- Database-backed rate limits for sending and reporting.
- Core `PrivateMessageComposerInterface` integration for optional Friends profile actions.
- Core `private-message.sent` event for optional Notifications delivery.

## Install or update

Upload the ZIP through **Admin → Modules → Install module package**, then install/update and enable Private Messages. Assign `private-messages.moderate` to moderator roles when needed.

Do not uninstall with data deletion when preserving conversations, blocks or reports. Moving the module directory does not remove its database tables.

## Verification

```shell
php vendor/bin/phpunit modules/PrivateMessages/Tests/PrivateMessagesPackageTest.php
php bin/cms module:check PrivateMessages
php bin/cms module:inspect PrivateMessages
```
