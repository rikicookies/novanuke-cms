# Search

Search provides an extensible, permission-aware search page across registered NovaNuke content providers.

## Capabilities

- Public search by query and supported content type.
- Registered providers for bundled content modules.
- Permission-aware result filtering and safe result highlighting.
- Admin diagnostics for search provider configuration.

The module declares the `search.manage` permission for its administrative capability. Public searching is available through the `/search` route; provider results remain subject to each content module’s visibility rules.

## Installation

Search is enabled by default on a fresh installation. Its migration and provider registration run through the normal module lifecycle. See [`docs/MODULES.md`](../../docs/MODULES.md) for module contracts and lifecycle guidance.

Search uses bounded query input and registered providers. A module must explicitly integrate with the search provider contract before its content appears in results.
