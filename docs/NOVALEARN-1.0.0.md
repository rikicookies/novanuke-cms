# NovaLearn 1.0.0 in the full source bundle

Core: 0.4.0-beta.1. Pages: 1.5.3. News: 1.8.1.

NovaLearn 1.0.0 is included at `themes/novalearn/`. The News admin-view namespace and protected layout correction is already integrated under `modules/News/`; do not install the separate overlay over this full source bundle. Pages Comments 1.5.3 remains integrated.

For a fresh installation, follow `INSTALLATION.md`. The installer activates NovaModern by default. To use NovaLearn, open Admin > Themes, install and activate it. For an existing site, follow `UPDATING.md`; after replacing source files, use Admin > Themes > Update for NovaLearn to publish its new CSS.

Validation on the source bundle before packaging: 36 PHPUnit tests / 122 assertions; release:check, release:smoke, theme:check and i18n:check passed. The two preexisting PHPUnit metadata deprecations remain. Browser fixtures covered 504 responsive cases. These checks do not replace a smoke test on the target Laragon or Bluehost installation.
