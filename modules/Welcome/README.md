# Welcome landing-page module

Welcome is an optional official NovaNuke Module API 1.0 package. It provides the `/welcome` landing page and can be selected as the site homepage under **Admin → General settings** while it is installed, enabled and publicly available.

## Install

Upload `novanuke-welcome-1.1.0.zip` from **Admin → Modules → Install a module package**. Review the detected module, then select **Install** and **Enable**. Uploading the package alone never runs migrations or enables routes.

If an earlier bundled copy was already installed, remove only the old `modules/Welcome` directory before uploading this package. Do not use destructive uninstall: the existing module record, migration history, settings and `welcome_messages` data remain valid.

Disabling Welcome removes `/welcome` and its homepage option. If it was the selected homepage, `/` safely falls back to the Core default page until Welcome is enabled again or another homepage is selected.
