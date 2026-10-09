# Pages

Pages provides static and informational content with publication, hierarchy and audience-aware access control.

## Capabilities

- Public page listing, landing and detail rendering.
- Admin creation and editing of page content, hierarchy and publication state.
- `pages.edit` and `pages.publish` permissions.
- Search-provider and sitemap integrations through the application module contracts.

Pages declares `pages.edit` and `pages.publish`. Administrative writes use the application authorization and CSRF protections. Page content is rendered through NovaNuke’s configured content pipeline.

## Installation

Pages is enabled by default on a fresh installation. Its migrations run through the normal module installation/update lifecycle. See [`docs/MODULES.md`](../../docs/MODULES.md) for module lifecycle and package guidance.

Review page audience and publication settings when moving content between drafts and the public site. Do not use Pages as a substitute for deployment configuration or secret storage.
