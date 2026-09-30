# NovaNuke SEO 1.1.0

Standalone sitemap and robots module for NovaNuke.

## Features

- Generates `/sitemap.xml` from the core `sitemap.collecting` extension contract.
- Generates `/robots.txt` with private and administrative routes disallowed.
- Validates the configured public Site URL before producing absolute URLs.
- Deduplicates and safely escapes contributed public paths.
- Keeps content modules independent from the SEO implementation.

## Install or update

Upload the ZIP through **Admin → Modules → Install module package**, then install/update and enable SEO. Configure the exact public HTTP or HTTPS Site URL under General Settings.

Disabling or removing the module directory only removes its two public endpoints. It does not alter News, Pages, Wiki or other content.

## Verification

```shell
php vendor/bin/phpunit modules/Seo/tests/SeoPackageTest.php
php bin/cms module:check Seo
php bin/cms module:inspect Seo
```
