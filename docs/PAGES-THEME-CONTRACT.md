# Pages public theme contract

Public Pages templates are rendered through the `pages` Twig namespace. A theme may replace presentation by providing matching files under:

`themes/<theme>/module-templates/pages/`

Supported core page templates currently include `default.twig` and `landing.twig`. If the active theme does not provide the requested template, NovaNuke falls back to `modules/Pages/views/`.

## Landing data contract

A Pages landing override may rely on `page`. The page payload includes the stored page fields and `content_html`, which has already passed through NovaNuke's ContentRenderer before Twig rendering.

The controller also provides optional presentation/action context used by the stock template: `edit_url`, `delete_url`, `content_csrf_token`, `delete_return_to`, and comment-related values when Comments integration is available.

Themes own presentation only. They must not duplicate authorization, content sanitization, CSRF generation, page lookup, access checks, or comment loading.

Admin Pages views use the reserved `admin-pages` namespace and are not theme-overridable.
