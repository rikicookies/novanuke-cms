# Statistics module

Statistics is an optional official NovaNuke Module API 1.0 package. It provides privacy-respecting aggregate traffic metrics, a protected dashboard at `/admin/statistics`, an optional public page at `/statistics`, maintenance pruning and the `statistics-summary` dynamic block.

## Install

Upload `novanuke-statistics-1.3.0.zip` from **Admin → Modules → Install a module package**. Uploading alone does not run migrations or enable runtime integrations.

If Statistics was previously bundled, move the old `modules/Statistics` directory outside the project before uploading this package. Do not perform a destructive uninstall. Select **Update** to register version 1.3.0 and preserve existing aggregate records, settings, migration history and block configuration, then enable the module.

Disabling Statistics stops collection, removes its routes and listeners, and causes its dynamic block to render nothing without deleting stored aggregates.
