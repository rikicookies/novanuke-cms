# Polls module

Polls is an optional official NovaNuke Module API 1.0 package. It provides scheduled single-choice and multiple-choice polls, public voting at `/polls`, administration at `/admin/polls`, and the `polls-active` dynamic block.

## Install

Upload `novanuke-polls-1.2.0.zip` from **Admin → Modules → Install a module package**. Review the detected module, then select **Install** and **Enable**. Uploading alone does not run migrations or enable routes.

If Polls was previously bundled, move the old `modules/Polls` directory outside the project before uploading this package. Do not perform a destructive uninstall: the existing module record, migration history, polls, options and votes remain valid. Select **Update** after upload when NovaNuke reports version 1.2.0, then enable the module.

Disabling Polls removes its public/admin routes, navigation and dynamic-block provider without deleting data. Any configured `polls-active` block safely renders nothing while the module is disabled.
