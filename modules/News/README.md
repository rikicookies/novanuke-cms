# News

News provides public editorial articles with categories, topics, tags, feeds and audience-aware publication.

## Capabilities

- Public news index, article pages and RSS feed support.
- Admin article editing, categories/topics/tags and publication workflow.
- `news.edit` and `news.publish` permissions for separate editorial duties.
- Search-provider and sitemap integrations through the application module contracts.

News declares `news.edit` and `news.publish`. Administrative changes use the application’s authorization and CSRF protections. Articles support the configured content formats and audience rules implemented by the module.

## Installation

News is enabled by default on a fresh installation. Its migrations run through the normal module lifecycle. See [`docs/MODULES.md`](../../docs/MODULES.md) for lifecycle and package guidance and [`docs/INTERNATIONALIZATION.md`](../../docs/INTERNATIONALIZATION.md) for translation conventions.

Review editorial permissions and publication visibility before launch. RSS and sitemap output reflect the content that is eligible for public display.
