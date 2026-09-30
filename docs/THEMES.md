# Developing NovaNuke themes

Themes contain Twig templates and public assets. They never contain executable PHP. Copy trusted themes manually into `themes/`, then install and activate them in `/admin/themes`.

## Structure

```text
themes/example/
  theme.json
  screenshot.svg
  layouts/
    default.twig
    full-width.twig
    two-sidebars.twig
  templates/
    home.twig
  partials/
    header.twig
    footer.twig
  blocks/
  module-templates/
    news/
    welcome/
  assets/
    css/
    js/
    images/
  language/
    en.json
    es.json
```

## Manifest

```json
{
  "name": "Example",
  "slug": "example",
  "version": "1.0.0",
  "description": "Example theme.",
  "author": "Designer",
  "cms_min_version": "0.4.0-beta.1",
  "screenshot": "screenshot.svg",
  "layouts": ["default", "full-width", "two-sidebars"],
  "positions": [
    "header", "left-sidebar", "right-sidebar",
    "before-content", "after-content", "footer"
  ],
  "settings": {
    "accent_color": {"type": "color", "label": "Accent", "default": "#3366ff"},
    "tagline": {"type": "text", "label": "Tagline", "default": "Welcome"},
    "show_tagline": {"type": "boolean", "label": "Show tagline", "default": true}
  }
}
```

Supported setting types are `text`, `boolean` and six-digit hexadecimal `color`. NovaNuke validates values on the server. Twig escapes text settings automatically.

## Template resolution

For a core template such as `home.twig`, NovaNuke searches:

1. active theme `templates/home.twig`;
2. active theme root;
3. core `resources/views/home.twig`.

For a module template such as `@welcome/index.twig`, NovaNuke searches:

1. active theme `module-templates/welcome/index.twig`;
2. module's registered Twig namespace path.

This lets a theme change a module's presentation without modifying the module. The module remains responsible for data, validation and authorization.

`module-templates/` is presentation-only. An override may change Twig markup and use the data already supplied by the module, but it cannot replace module PHP, routes, controllers, permissions, validation, CSRF handling, or other business logic. Keep the module's own `views/` complete: they are the canonical fallback whenever the active theme does not provide a matching file.

Override directory names are Twig module namespaces (for example `news`, `pages`, or `web-links`) and must use lowercase letters, numbers, hyphens, or underscores, beginning with a letter. Unrelated or malformed directories are ignored during theme boot.

## Theme translations

The active theme automatically exposes its JSON catalogues through the stable `theme` namespace. Use `{{ trans('theme::home.heading') }}` for theme-owned labels and the unprefixed core keys for shared actions. See `docs/INTERNATIONALIZATION.md`.

## Twig safety

- Twig autoescape remains enabled.
- Themes cannot execute PHP.
- Do not mark user-controlled content as raw.
- Do not generate arbitrary template names from request input.
- Layout names declared in the manifest use a restricted identifier format.

The active theme receives a global object:

```twig
{{ theme.slug }}
{{ theme.asset_base }}
{{ theme.settings.accent_color }}
{{ theme.positions }}
{{ theme.layouts }}
```

## Assets

On installation, update and activation, NovaNuke copies allowed assets to:

```text
public/assets/themes/{slug}/
```

Allowed extensions cover CSS, JavaScript, common images and web fonts. PHP and unknown executable formats are rejected. Symbolic links are rejected. Stale published assets are removed before republishing.

Reference assets with:

```twig
<link rel="stylesheet" href="{{ theme.asset_base }}/css/theme.css">
```

## Layouts and block positions

Themes declare which layouts and block positions they support. Phase 4C connects the positions to the block manager. A theme may omit sidebar markup in its default layout and provide a separate two-sidebar layout.

## Lifecycle

- Available: files detected on disk.
- Installed: manifest and settings registered; assets published.
- Active: template paths and module overrides are loaded.
- Update available: disk version is newer than the installed version.
- Missing files: database record exists but theme directory is absent.

The active theme cannot be uninstalled. Activate a different installed theme first. Uninstalling removes its database record and published public assets, but leaves the manually copied source directory untouched.

NovaModern and NovaLearn are the supported bundled themes. NovaModern is the default responsive public/Admin workspace theme; see `docs/NOVAMODERN.md`.

## Public module view contract (Beta 1+)

Bundled modules keep public presentation behind their module Twig namespace. A public controller renders `@news/index.twig`, `@pages/default.twig`, `@downloads/show.twig`, and equivalent namespaced views. Themes may override those public views under `module-templates/<module-slug>/`.

Administrative templates are deliberately outside that contract. Bundled modules register a reserved `admin-<module-slug>` namespace pointing directly at their own `views/admin/` directory, and Admin controllers render that namespace. Theme discovery ignores `module-templates/admin-*` directories. This keeps the public site fully themeable without allowing a front-end theme to replace security-sensitive Admin presentation.

Theme overrides must preserve the behavior expected by the route: form methods/actions, CSRF fields, required identifiers, and data-dependent actions still come from the module contract. A theme may completely change markup, classes, hierarchy, icons and responsive layout; it must not invent authorization decisions or duplicate server-side business logic.
