# NovaNuke

NovaNuke is a lightweight modular CMS with an old-school portal spirit, written from scratch for PHP 8.3+.

Current public release: **0.4.0-beta.1**. This is the first public NovaNuke release; earlier development snapshots are not public releases and are not supported upgrade sources.

## NovaLearn 1.0.0 source bundle

This distribution combines the NovaNuke 0.4.0-beta.1 core with the NovaLearn 1.0.0 public theme and the News admin-view namespace fix. The core and News module versions have not changed. Fresh installs still activate NovaModern by default; install and activate NovaLearn from Admin > Themes if desired. Existing sites: preserve `.env`, `storage/installed.lock`, `storage/private/` and `public/uploads/`; see `docs/UPDATING.md` and `docs/NOVALEARN-1.0.0.md`.

The source ZIP omits Composer `vendor/`, installed-site data and published theme assets. Run Composer after extraction and let theme installation/update publish its assets.

## Features

- Web installer, authentication, profiles, roles, permissions and administration.
- Modular content system with installable modules and Module API 1.0.
- Twig themes, blocks, menus and responsive public/admin interfaces.
- Bundled News, Comments, Pages, Downloads, Search, Friends and Web Links modules.
- Membership/VIP access, notifications, private messaging, media, polls, statistics, SEO and other core-integrated capabilities where enabled.
- English/Spanish internationalization, SMTP support, backups, maintenance and security tooling.
- PDO prepared statements, CSRF protection, rate limits, output escaping and sanitized HTML/Markdown content.

NovaNuke does not include a forum and never accepts executable PHP through the administration panel.

## Requirements

- PHP 8.3 or newer
- Composer 2
- MySQL 8+ or compatible MariaDB
- PDO MySQL, DOM, Fileinfo, JSON, Mbstring and OpenSSL PHP extensions
- Apache `mod_rewrite` or equivalent web-server routing

The web document root must point to `public/`.

## Fresh installation

```bash
composer install --no-dev --optimize-autoloader
```

Open the site in a browser and complete the web installer. It creates `.env` and `storage/installed.lock`. Do not ship either file in a source/release archive. See `docs/INSTALLATION.md` and `docs/PRODUCTION.md` for deployment details.

For development:

```bash
composer install
composer test:checkpoint
php bin/cms release:check
php bin/cms release:smoke
```

## Updating

0.4.0-beta.1 is the public upgrade baseline. There are no supported upgrades from private pre-release snapshots. Future public upgrade paths will be documented in `docs/UPDATING.md`.

## Documentation

Technical documentation lives under `docs/`. Start with `INSTALLATION.md`, `PRODUCTION.md`, `SECURITY_CHECKLIST.md`, `MODULES.md`, `THEMES.md`, `INTERNATIONALIZATION.md`, `TESTING.md` and `UPDATING.md`.

## License

NovaNuke is released under the MIT License. See `LICENSE`.
