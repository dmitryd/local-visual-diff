# Local Visual Diff

Local Visual Diff is a PHP 8.3 command-line tool for visually comparing two locally reachable websites.

It is designed for cases such as:

- comparing an old site against a migrated site
- checking a framework upgrade visually
- validating a relaunch or alternate implementation
- reviewing whether a candidate site still looks like the baseline site

The tool is local-first. It uses a locally installed Chromium-based browser to render pages, captures screenshots, compares them visually, and writes an HTML report with links to the baseline page, candidate page, and generated artifacts.

## What The Tool Does

Given two base URLs, the tool:

1. opens the baseline homepage
2. follows same-origin links recursively on the baseline site
3. maps every discovered normalized target to the candidate site
4. renders both pages in a local browser
5. compares the screenshots visually
6. writes a report under `var/results/run-YYYYMMDD-HHMMSS/`

The report lists each page as one of:

- `Matching`
- `Different`
- `HTTP error ...`
- `Runtime error`

When pages differ, the report can link to:

- baseline screenshot
- candidate screenshot
- overlay image
- diff image
- context image for very tall pages

## Core Behavior

- Crawling happens only on the baseline site.
- Candidate pages are never crawled directly. They are derived from the same normalized target path/query.
- Same-origin links are followed recursively by default.
- Query-string variants are treated as separate pages by default.
- Some query parameter names can be ignored during crawl deduplication with a regular expression.
- Relative links are resolved like a browser would:
  - redirects change the effective document URL
  - `<base href="...">` is honored
  - absolute URLs remain absolute
  - root-relative URLs remain root-relative
- Visual comparison is screenshot-based, not DOM-based.

This tool intentionally does **not** compare:

- DOM structure
- CSS file names
- JavaScript bundle names
- generated asset names

## Requirements

- PHP `^8.3`
- Composer
- `ext-curl`
- `ext-dom`
- `ext-imagick`
- `ext-json`
- `ext-libxml`
- `ext-mbstring`
- `ext-sockets`
- a locally installed Chromium-based browser

The current Composer dependencies are:

- `chrome-php/chrome`
- `symfony/console`
- `typo3fluid/fluid`

## Installation

Install dependencies:

```bash
composer install
```

Run the tool:

```bash
php bin/visual-diff https://baseline.local https://candidate.local
```

## Output

By default, a run writes to:

```text
var/results/run-YYYYMMDD-HHMMSS/
```

Typical output files:

- `report.html`
- `report.json`
- `pages/<page-slug>/...`

Stored image artifacts use `WebP` by default to reduce size. The comparison itself still uses temporary lossless PNG screenshots internally so the visual diff remains accurate.

## Configuration Files

The tool reads configuration in this order:

1. `config/defaults.ini`
2. `config/local.ini`
3. CLI options

That means:

- `defaults.ini` contains shared project defaults
- `local.ini` contains machine-specific overrides and should not be committed
- CLI options override both files for a single run

### config/defaults.ini

This file is tracked in Git and contains the shared defaults for the project.

Current sections:

- `[browser]`
- `[compare]`
- `[crawl]`
- `[report]`
- `[run]`

### config/local.ini

This file is optional and intended for local machine overrides such as:

- browser executable path
- different viewport settings
- different preferred browser language
- local crawl TLS behavior
- different artifact image format

Example:

```ini
[browser]
path = "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser"
accept_language = "de, en"
scroll_warmup = false

[crawl]
fetch_verify_tls = false

[report]
artifact_image_format = "webp"
```

## Configuration Options

### [browser]

- `path`
  Browser executable path. If empty or invalid, the tool also tries common browser locations for the current OS.
- `accept_language`
  Language preference used for browser capture. Default: `"de, en"`.
- `viewport_width`
  Browser viewport width in pixels.
- `viewport_height`
  Browser viewport height in pixels.
- `settle_ms`
  Extra wait time after navigation before screenshot capture.
- `timeout_ms`
  Timeout for browser operations.
- `scroll_warmup`
  Whether the browser should scroll through the page before capture. Default: `false`. Enable this only for sites that need scroll-driven lazy content, because scrolling can trigger sticky menus or other JavaScript state that would not be visible in the initial browser view.
- `max_fullpage_height`
  Height threshold for large-page fallback behavior.
- `context_padding`
  Extra surrounding area around detected differences in tall-page context artifacts.

### [compare]

- `shift_tolerance`
  Allowed local pixel drift before a difference is counted. Default: `0`.
- `color_threshold`
  Allowed per-channel color difference. Default: `0`.
- `overlay_opacity`
  Opacity used for overlay rendering.

### [crawl]

- `crawl_homepage_links`
  Recursive crawl enable/disable. Default: `true`.
- `ignored_query_param_name_pattern`
  Regular expression matching query parameter names that should be stripped before deduplication.
- `fetch_verify_tls`
  Whether crawl-time cURL fetching verifies TLS certificates. Default in this project is `false`, which is useful for local DDEV-style environments.
- `max_pages`
  Maximum number of pages to compare. `0` means unlimited.

### [report]

- `output_dir`
  Parent directory for generated runs.
- `artifact_image_format`
  Stored artifact format such as `webp` or `png`. Default: `webp`. If the requested format is not supported by the local Imagick build, the tool falls back to `png`.

### [run]

- `capture_matching_screenshots`
  Keep screenshots even for matching pages.
- `overwrite`
  Allow reuse of an existing output directory.

## Command-Line Options

Main command form:

```bash
php bin/visual-diff [options] <baseline-base-url> <candidate-base-url>
```

Important options:

- `--output-dir`
- `--browser-path`
- `--accept-language`
- `--viewport-width`
- `--viewport-height`
- `--settle-ms`
- `--timeout-ms`
- `--scroll-warmup`
- `--max-fullpage-height`
- `--context-padding`
- `--shift-tolerance`
- `--color-threshold`
- `--ignored-query-param-name-pattern`
- `--insecure-crawl-fetch`
- `--max-pages`
- `--artifact-image-format`
- `--capture-matching-screenshots`
- `--overwrite`
- `--profile`

Console verbosity:

- default output shows progress lines such as `Comparing /about`
- `-v` also shows detailed page diagnostics
- `-q` suppresses normal progress output

## Usage Examples

Basic run:

```bash
php bin/visual-diff https://legacy.local https://candidate.local
```

Limit the run to the homepage only:

```bash
php bin/visual-diff --max-pages=1 https://legacy.local https://candidate.local
```

Show detailed diagnostics while running:

```bash
php bin/visual-diff -v https://legacy.local https://candidate.local
```

Allow small layout drift of 3 pixels:

```bash
php bin/visual-diff --shift-tolerance=3 https://legacy.local https://candidate.local
```

Ignore selected query parameter namespaces during crawl deduplication:

```bash
php bin/visual-diff \
  --ignored-query-param-name-pattern='/^(tx_solr\[|tx_my\[)/' \
  https://legacy.local \
  https://candidate.local
```

Store artifacts as PNG instead of WebP:

```bash
php bin/visual-diff \
  --artifact-image-format=png \
  https://legacy.local \
  https://candidate.local
```

Use an explicit output directory:

```bash
php bin/visual-diff \
  --output-dir=var/results/manual-run \
  https://legacy.local \
  https://candidate.local
```

Useful for local DDEV-like certificates:

```bash
php bin/visual-diff \
  --insecure-crawl-fetch \
  https://legacy.local \
  https://candidate.local
```

## Notes

- Browser rendering can differ if installed fonts differ between environments.
- Background videos and motion are disabled during capture to reduce false positives.
- If the baseline homepage fails with an HTTP error, the tool reports that failure and does not continue discovery from that page.
- Matching pages do not keep screenshots by default, which helps reduce disk usage.

## Attribution

This tool was created by ChatGPT/Codex after heavy brainstorming and discussions with Dmitry Dulepov.
