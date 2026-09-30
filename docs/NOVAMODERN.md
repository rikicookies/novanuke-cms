# NovaModern

NovaModern is NovaNuke's responsive public and administrative workspace theme. It provides the default modern layout for a fresh installation and is one of the two supported bundled themes going forward, alongside NovaLearn.

## Availability and activation

NovaModern is available when `themes/novamodern/` is present and its manifest is valid. A fresh installation activates NovaModern by default. An administrator can install and activate it from **Admin → Themes** when it is available on disk.

The theme manager distinguishes these states:

- Available: the theme files and manifest are detected.
- Installed: the manifest and settings are registered and assets are published.
- Active: the theme's templates, translations, public module overrides and globals are loaded.
- Update available: the on-disk version is newer than the installed version.
- Missing files: the database record exists but the theme directory is absent.

Install, update and activation publish the theme's assets below:

```text
public/assets/themes/novamodern/
```

The manager does not allow uninstalling the active theme; activate another installed theme first.

## Manifest

The current manifest is `themes/novamodern/theme.json`:

```json
{
  "name": "NovaModern",
  "slug": "novamodern",
  "version": "1.1.1",
  "description": "A lightweight responsive NovaNuke theme with distinct public and administrative workspaces.",
  "author": "NovaNuke Project",
  "cms_min_version": "0.4.0-beta.1",
  "screenshot": "screenshot.svg",
  "layouts": ["default", "full-width", "two-sidebars"],
  "positions": [
    "header",
    "left-sidebar",
    "right-sidebar",
    "before-content",
    "after-content",
    "footer"
  ]
}
```

NovaModern also declares these settings:

| Setting | Type | Default | Purpose |
| --- | --- | --- | --- |
| `accent_color` | `color` | `#5b8def` | Used by the current layout for the CSS accent value. |
| `site_tagline` | `text` | `A modern modular CMS.` | Declared theme tagline value available through `theme.settings`. |
| `show_version` | `boolean` | `true` | Declared development-version display setting available through `theme.settings`. |

Theme setting types are limited by the current manifest parser to `text`, `boolean` and `color`. Color values must be six-digit hexadecimal colors. Text settings are trimmed and limited to 200 characters.

## Structure and layouts

NovaModern contains:

```text
themes/novamodern/
  theme.json
  language/en.json
  language/es.json
  layouts/default.twig
  layouts/full-width.twig
  layouts/two-sidebars.twig
  templates/admin/dashboard.twig
  menus/navigation.twig
  partials/
  blocks/region.twig
  assets/css/novamodern.css
  assets/js/navigation.js
  assets/images/icons.svg
```

The `default`, `full-width` and `two-sidebars` layouts are declared in the manifest. The latter two currently extend the default layout. The theme exposes the declared block positions through the `theme` global.

NovaModern has separate public and Admin workspace partials. Public navigation uses the theme menu macro and sidebar. Admin navigation renders the Core-provided `admin_navigation` groups and items. The Admin dashboard is overridden at `templates/admin/dashboard.twig`.

## Assets and responsive behavior

Theme assets belong under `assets/`. The current theme includes CSS, JavaScript and SVG icon assets. NovaNuke validates theme assets, rejects symbolic links and unsupported executable formats, stages publication, and exposes the published root through `theme.asset_base`.

Reference an asset from Twig through the theme global:

```twig
<link rel="stylesheet" href="{{ theme.asset_base }}/css/novamodern.css">
```

The navigation JavaScript and CSS implement the responsive public/Admin navigation behavior, including the navigation toggle and sidebar states. Preserve the existing toggle attributes and IDs when customizing navigation behavior:

```html
data-nav-toggle
data-sidebar
```

The source does not promise a fixed breakpoint list or a separate JavaScript API; customize through the existing CSS, templates and asset files.

## Translations

NovaModern keeps English and Spanish catalogues in:

```text
themes/novamodern/language/en.json
themes/novamodern/language/es.json
```

Theme catalogues are exposed through the stable `theme` namespace:

```twig
{{ trans('theme::home.heading') }}
```

The theme also uses Core messages such as `common.sign_in`, `common.sign_out`, `common.navigation` and `admin.title`. Keep theme-owned messages in `theme::...` and keep shared Core messages in the Core catalogue. English and Spanish keys must remain in parity; see [`INTERNATIONALIZATION.md`](INTERNATIONALIZATION.md).

## Public module overrides and Core templates

The active theme's root and `templates/` paths are prepended to the main Twig loader. This lets NovaModern provide theme layouts, pages and Admin workspace templates.

Public module presentation can be overridden under:

```text
themes/novamodern/module-templates/<module-slug>/
```

For example, a `module-templates/news/` directory can replace public News presentation. The module still owns its data, validation, permissions, CSRF handling and business logic.

Directories named `admin-*` under `module-templates/` are ignored by theme boot. Administrative module namespaces are reserved for module-owned views, so a theme must not use public overrides to replace security-sensitive Admin module markup.

## Safe customization

- Keep `theme.json` valid and preserve the existing slug, declared layout names and position names.
- Keep PHP out of the theme. Themes contain Twig templates and public assets.
- Use `theme.asset_base` for published assets.
- Use `trans()` and maintain both language catalogues for visible strings.
- Preserve form methods, actions, CSRF fields, required identifiers and data-dependent actions supplied by module contracts.
- Keep public module overrides presentation-only.
- Do not create `module-templates/admin-*` overrides.
- Preserve navigation controls such as `data-nav-toggle`, `data-sidebar`, `aria-expanded` and `aria-controls` when changing responsive navigation.
- Run the theme and translation checks before packaging:

  ```bash
  php bin/cms theme:check
  php bin/cms i18n:check
  ```

General theme structure, lifecycle, override boundaries and asset rules are documented in [`THEMES.md`](THEMES.md). NovaModern-specific changes should remain limited to its manifest, templates, catalogues and assets.
