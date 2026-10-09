# Downloads

Downloads provides a categorized download catalog with local or external targets, access rules, reporting and download tracking.

## Capabilities

- Public download listing and detail pages.
- Administrator creation, editing, deletion and category management.
- Draft/publish workflow using `downloads.publish`.
- Local upload validation and orphan-file reporting.
- Member/VIP access options where the surrounding membership features support them.

The module declares `downloads.manage` and `downloads.publish`. Administrative write forms require the application CSRF token. Local files are stored below the configured uploads area and are validated before use; do not treat uploaded content as trusted executable code.

## Installation

Downloads is enabled by default on a fresh installation. Its migrations run through the normal module lifecycle. See [`docs/MODULES.md`](../../docs/MODULES.md) for installation, lifecycle and standalone package guidance, and [`docs/PRODUCTION.md`](../../docs/PRODUCTION.md) for upload/storage operations.

Review access and publishing permissions before making downloads public. Use the orphan check after backups and review its dry-run output before any deletion operation.
