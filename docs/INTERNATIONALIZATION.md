# NovaNuke internationalization

NovaNuke stores translations as JSON catalogues. The current bundled catalogues use English (`en`) and Spanish (`es`), and the catalogue auditor requires both for Core, modules and themes that expose language directories.

## Locales and catalogues

Locale codes must match:

```text
[a-z]{2}
[a-z]{2}_[A-Z]{2}
```

Examples are `en`, `es`, `en_US` and `es_MX`.

Core catalogues live in `language/`:

```text
language/en.json
language/es.json
```

Module catalogues live in the module's `language/` directory. Theme catalogues live in the theme's `language/` directory. Each catalogue is a JSON object whose values are strings.

Translation keys must use lowercase letters, numbers, dots, hyphens or underscores and begin with a lowercase letter. The current validator accepts keys up to 191 characters.

The application locale and fallback locale come from `APP_LOCALE` and `APP_FALLBACK_LOCALE`. `config/app.php` defaults both to `en`. The `LocaleRegistry` discovers valid Core catalogue files, requires the English Core catalogue, and supplies supported locale choices to the application.

## Namespaces

The translator has one namespace per catalogue directory:

| Owner | Namespace | Example |
| --- | --- | --- |
| Core | `core` implicitly | `common.sign_in` |
| Module | module slug | `news::title` |
| Active theme | `theme` | `theme::home.heading` |

Unprefixed keys use the Core namespace. Module keys use the module slug followed by `::`. Theme keys always use the stable `theme::` namespace for whichever theme is active.

Modules register their language directory while the enabled provider is registered. The active theme registers its language directory while the theme boots. A module or theme that is not active/enabled does not become the current runtime translation source.

## Twig usage

The Twig environment exposes the `trans` function:

```twig
{{ trans('common.primary_navigation') }}
{{ trans('news::title') }}
{{ trans('theme::home.heading') }}
```

Pass scalar parameters with a map. Parameter names use lowercase letters, numbers and underscores:

```twig
{{ trans('users.directory.count', {count: result.total}) }}
{{ trans('news::by', {author: article.username}) }}
```

Catalogue messages use matching `{name}` placeholders.

## PHP usage

Inject or resolve `NovaNuke\Core\I18n\Translator` and call `translate()`:

```php
$title = $translator->translate('news::title');
$message = $translator->translate('news::by', ['author' => $username]);
```

`Translator::translate()` accepts scalar or null parameter values. Null becomes an empty string. Invalid parameter names are ignored.

## Fallback behavior

For a valid namespace and key, the translator checks:

1. The current locale catalogue.
2. The configured fallback locale catalogue.
3. The original key itself.

If the namespace is not registered, the message key is malformed, the message is missing, or neither catalogue contains it, the original key is returned. A missing translation therefore appears as a raw key such as `news::title`; it is not silently replaced with invented text.

The translator falls back to `en` when an invalid configured locale or fallback locale is supplied during construction. `setLocale()` rejects invalid locale formats.

## Adding English and Spanish messages

Add the same key to both catalogues:

```json
{
  "title": "Reading List",
  "empty": "No saved items.",
  "count": "{count} saved item(s)."
}
```

For a module, keep the files at:

```text
modules/ReadingList/language/en.json
modules/ReadingList/language/es.json
```

Use the module namespace in templates and PHP:

```twig
{{ trans('reading-list::empty') }}
```

For a theme, use:

```text
themes/novamodern/language/en.json
themes/novamodern/language/es.json
```

Use `theme::...` for theme-owned strings. Keep shared Core actions in the Core catalogue instead of duplicating them in a theme.

## Validation and audit tooling

Run the read-only catalogue audit with:

```bash
php bin/cms i18n:check
```

`CatalogueAuditor` scans the Core `language/` directory and every `language/` directory under `modules/` and `themes/`. It checks that JSON parses to an object, keys and values have the supported formats, English and Spanish catalogues exist, and their top-level keys match.

The module diagnostic also checks a module's language directory and English/Spanish key parity. The theme distribution checks inspect the supported theme package structure.

## Common mistakes

- Hardcoding visible UI text in a module or theme instead of adding it to a catalogue.
- Using `theme::...` for a module message, or using a module namespace for a theme message.
- Adding a key to `en.json` but not `es.json`.
- Using uppercase, spaces or unsupported punctuation in keys.
- Calling `trans()` with an accidental missing key and treating the raw key as a valid translation.
- Forgetting placeholders or changing `{name}` to a different placeholder in one locale.
- Assuming a disabled module's catalogue is available at runtime.
- Marking user-controlled content as a translation message or bypassing normal Twig escaping.

Keep visible strings translated, keep catalogues in the owning package, and run `i18n:check` before packaging.
