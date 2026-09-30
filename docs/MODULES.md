# Developing NovaNuke modules

NovaNuke modules are installable PHP packages that register services, routes, views, translations, permissions, migrations and event integrations through the Module API. The current Module API version is `1.0`.

## Start with a scaffold

Generate a starting module with:

```bash
php bin/cms module:make "Reading List"
```

The scaffold creates a directory under `modules/` with:

```text
modules/ReadingList/
  module.json
  src/ReadingListModule.php
  views/index.twig
  language/en.json
  language/es.json
  database/migrations/
  tests/
  README.md
```

The generated provider registers a view namespace and a named public route. Treat it as a starting point: add the repository, controllers, input objects, templates and tests your module needs.

## Manifest

Every module has a `module.json`. The loader requires these fields:

```json
{
  "name": "Reading List",
  "slug": "reading-list",
  "version": "1.0.0",
  "api_version": "1.0",
  "description": "Saved reading items.",
  "author": "NovaNuke Project",
  "provider": "Modules\\ReadingList\\src\\ReadingListModule",
  "cms_min_version": "0.4.0-beta.1",
  "php_min_version": "8.3.0",
  "dependencies": {},
  "permissions": ["reading-list.manage"]
}
```

The manifest also supports `events` and an optional `navigation` object. Navigation supports `label`, internal `url`, optional `icon`, numeric `order`, `audience`, `public_landing`, and optional `enabled_setting`.

Manifest rules are enforced by `ModuleManifest`:

- `slug` uses lowercase letters, numbers and hyphens.
- Versions use semantic-version format.
- The provider must be inside the module's `Modules\\<Directory>\\` namespace.
- `dependencies` maps a module slug to a minimum installed version.
- Permissions must begin with the module slug and use lowercase dot-separated names, such as `reading-list.manage`.
- Module events use lowercase dot-separated names.

The declared API version, PHP version, CMS version and dependencies are checked before installation, update and enable operations.

## Provider lifecycle

The provider class implements `NovaNuke\Core\Modules\ModuleInterface`:

```php
<?php

declare(strict_types=1);

namespace Modules\ReadingList\src;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\Modules\ModuleContext;
use NovaNuke\Core\Modules\ModuleInterface;
use NovaNuke\Core\View\ViewRenderer;

final class ReadingListModule implements ModuleInterface
{
    public function register(ModuleContext $context): void
    {
        $context->container
            ->get(ViewRenderer::class)
            ->addNamespace('reading-list', $context->basePath . '/views');
    }

    public function boot(ModuleContext $context): void
    {
        $context->router->get(
            '/reading-list',
            static function (Request $request, Container $container): Response {
                return Response::html(
                    $container->get(ViewRenderer::class)->render('@reading-list/index.twig'),
                );
            },
            'reading-list.index',
        );
    }
}
```

`register()` is for bindings, view namespaces and other registration work. `boot()` is for routes and event listeners that should become active with the module. `ModuleContext` provides the manifest, container, router, event dispatcher and module `basePath`.

Enabled modules are registered and booted during application startup. NovaNuke scopes their container, router, view and translation mutations so a failed registration or boot can be rolled back cleanly.

## Routes

Use the router supplied by `ModuleContext`:

```php
$context->router->get('/reading-list', $handler, 'reading-list.index');
$context->router->post('/reading-list/save', $handler, 'reading-list.save');
```

The router supports `get`, `post`, `put`, `patch` and `delete`. Keep route paths internal and name literal routes with the module slug prefix. The module diagnostic checks that statically detected route names begin with that prefix.

Use the existing request, response, controller, CSRF and authorization services rather than putting business logic in route closures. The Quotes module is the maintained small CRUD example.

## Services and extension points

The module container is available through `ModuleContext::$container`. Modules commonly bind repositories or inputs there and resolve Core services such as `PDO`, `ViewRenderer`, `Translator`, `AuthorizationService`, `SessionManager` and `CsrfTokenManager`.

The event dispatcher is available through `ModuleContext::$events`:

```php
use NovaNuke\Core\Admin\AdminMenuBuilding;
use NovaNuke\Core\Events\EventName;

$context->events->listen(
    EventName::ADMIN_MENU_BUILDING,
    static function (object $event): void {
        if ($event instanceof AdminMenuBuilding) {
            $event->add('reading-list::admin.title', '/admin/reading-list', 'reading-list.manage', 'bookmark');
        }
    },
);
```

The current Core contracts expose typed event objects for extension points such as Admin menu construction, search-provider registration, sitemap collection, profile statistics, media-usage checks and comment-target checks. Use the event class and `EventName` constant already used by the corresponding Core feature; do not invent event names.

`AdminMenuBuilding::add()` requires a translation key, an internal path, a permission, and optional lowercase icon/group names. The permission is checked by the Admin navigation layer.

## Permissions and dependencies

List module permissions in `module.json`. Installation and update register them in the Core permission table. Use the module permission in controller authorization checks and Admin menu entries. A permission does not grant access by itself; the controller or route flow must still enforce authorization.

Dependencies use minimum installed versions:

```json
"dependencies": {
  "media": "1.2.0"
}
```

The dependency must be installed before the module can be installed or enabled. Enabled dependent modules must be disabled before their dependency can be disabled.

## Views and translations

Register the module's view directory under its lowercase module namespace:

```php
$context->container
    ->get(ViewRenderer::class)
    ->addNamespace('reading-list', $context->basePath . '/views');
```

Render the namespace with `@reading-list/...`. Administrative module views normally use a separate `admin-<slug>` namespace so themes cannot replace security-sensitive Admin module templates. Public module templates may be overridden by the active theme under `module-templates/<slug>/`.

Put module catalogues in `language/en.json` and `language/es.json`. When an enabled module registers, NovaNuke exposes its catalogue under the module slug namespace, for example `reading-list::title`.

Twig:

```twig
<h1>{{ trans('reading-list::title') }}</h1>
```

PHP:

```php
$label = $translator->translate('reading-list::title');
```

See [`INTERNATIONALIZATION.md`](INTERNATIONALIZATION.md) for catalogue and fallback rules.

## Migrations and module-owned resources

Module migrations live in `database/migrations/` and use the timestamped filename format checked by `ModuleDiagnostic`:

```text
YYYY_MM_DD_HHMMSS_description.php
```

Each migration returns an object implementing the Core `Migration` contract. Recoverable migrations may also implement `RecoverableMigration` and expose verified final states through `isApplied()` and `isRolledBack()`.

Migrations run when a module is installed or updated. Module migration history is stored separately from Core migration history. An interrupted operation must be reconciled with:

```bash
php bin/cms migrate:recover --module=reading-list
```

Keep schema changes in the module's own migration directory and use the module's tables, permissions and resources as its ownership boundary. On uninstall, disabling happens first. Uninstall without the delete-data option preserves module data and migration records; uninstall with data deletion rolls back module migrations and removes module permissions.

For migrations that change existing data, follow the repository's recoverable/idempotent patterns and avoid assuming that a large backfill is instantaneous.

Modules may contain local assets. NovaNuke does not automatically publish a generic module asset directory like it does for themes. Current modules expose assets through application-owned routes when needed, as Landing and Wiki do, with path validation and response handling in the module.

## Install, update, enable and disable

For an on-disk or uploaded package, the Admin Modules flow:

1. Detects and validates `module.json` and the declared provider.
2. Checks API, PHP, CMS and dependency compatibility.
3. Installs module migrations and registers permissions.
4. Records the installed module and version.
5. Enables the module separately.

Uploading a ZIP alone does not run migrations or enable routes. The package installer stages extraction, rejects unsafe paths and symlinks, requires one top-level module directory, and publishes the package atomically.

Updates require a newer manifest version, rerun module migrations, refresh permissions and preserve the installed module record. Enabling checks dependencies and then activates the provider lifecycle. Disabling removes the module from the enabled boot set without deleting its data. A module with enabled dependents cannot be disabled.

Useful read-only checks are:

```bash
php bin/cms module:check ReadingList
php bin/cms module:inspect ReadingList
php bin/cms module:list
```

`module:check` validates the manifest, compatibility, provider, migration filenames/contracts, catalogues, README and literal route names. `module:inspect` reports the module contract and extension inventory.

## ModuleScaffolder relationship

`NovaNuke\Core\Developer\ModuleScaffolder` is the implementation behind `php bin/cms module:make`. It creates the module directories, a valid starting manifest, provider, view, English/Spanish catalogues, tests directory and README. The generated README points back to this guide. The scaffold is intentionally minimal; it does not create repositories, controllers, migrations or event integrations automatically.

## Before installing

Run the static module check, review permissions and dependencies, inspect migrations, and keep the module's README current. Use the Quotes module as the closest complete reference, then verify the module against the current Module API and Core contracts.
