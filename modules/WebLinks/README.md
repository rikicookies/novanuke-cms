# Web Links

Web Links provides a curated external-link catalog with categories, submissions, protected visit counts and abuse reports.

## Capabilities

- Public link catalog, categories, detail pages and safe external redirects.
- Authenticated member submissions with rate limiting.
- Admin link/category management, audience selection and publication workflow.
- Link visits and abuse reports with administrator resolution.
- Public, member and VIP visibility options where membership support is available.

The module declares `web-links.manage`. Administrative writes and member submissions use authorization and CSRF protections. URLs are limited to HTTP/HTTPS without embedded credentials; review submitted destinations before publishing.

## Installation

Web Links is enabled by default on a fresh installation. Its migrations run through the normal module installation/update lifecycle. See [`docs/MODULES.md`](../../docs/MODULES.md) for module lifecycle and package guidance.

The module requires the application key for protected visit/report identity handling. Configure the site normally before enabling public submissions, and review reports from the admin area.
