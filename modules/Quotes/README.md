# Quotes reference module

Quotes is the official small-but-complete NovaNuke Module API 1.0 example. It demonstrates a manifest, provider lifecycle, namespaced routes, Twig namespace, migration, repository binding, public rendering, admin permission checks, CSRF validation, activity logging, admin-menu extension, translations and tests.

It intentionally avoids optional cross-module dependencies so the module can be read in isolation. Copy the patterns, not the module name.

## Install

Upload the official `novanuke-quotes-1.0.0.zip` from **Admin → Modules → Install a module package**. Review the detected module, then select **Install** and **Enable**. Uploading the package alone never runs migrations or enables its routes.

Disabling Quotes removes its runtime integrations without deleting data. Uninstalling without **Delete module tables and data** preserves its migration records and tables for a later reinstall.


## 1.0.1

Admin UI updated to the NovaNuke Admin UI v2 visual system. Module behavior and public view remain unchanged.
