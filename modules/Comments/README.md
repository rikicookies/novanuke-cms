# Comments

Comments provides threaded discussions attached to supported content targets, with moderation and abuse-report handling.

## Capabilities

- Public comment threads, replies, editing and reactions.
- Guest comments when enabled by the Comments policy; authenticated comments otherwise.
- Abuse reports and a moderation queue for authorized moderators.
- Admin settings for guest comments and moderation requirements.

The module declares the `comments.moderate` permission. Its public state-changing forms use the application CSRF token, and comment targets must be accepted by a registered content integration.

## Installation

Comments is one of the seven modules enabled by default on a fresh NovaNuke installation. Its migrations are applied through the normal module installation/update lifecycle. For module checks, lifecycle procedures and package structure, see [`docs/MODULES.md`](../../docs/MODULES.md).

Review the guest-comment and moderation settings before opening public submissions. The module does not replace site-wide abuse, rate-limit or content-moderation policy.
