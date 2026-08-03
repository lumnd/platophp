# PlatoPHP documentation

The documentation site is generated from paired Chinese and English Markdown sources.

```text
source/zh-CN/   Simplified Chinese sources
source/en/      English sources with matching slugs
manifest.json   Navigation, localized UI strings, output paths, and source hashes
assets/         Shared site styles, script, and images
site/           Generated output; not committed
```

## Build

Install the repository development dependencies, then run the PHP builder:

```bash
composer install
php docs/build.php
```

Repository verification must run in the configured PHP Docker container. The builder uses
`league/commonmark` with GitHub-Flavored Markdown, writes both locales, creates language switches
and `llms-*.txt`, copies assets into a self-contained site, and validates local links.

Open `docs/site/index.html` after a successful local build. GitHub Pages publishes `docs/site/`
through `.github/workflows/docs.yml`.

## Editing

Every page slug must exist in both locale sections of `manifest.json`, in the same order. Update the
Chinese and English source together. Do not edit generated HTML or generated hashes by hand.
