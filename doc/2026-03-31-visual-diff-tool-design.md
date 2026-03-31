# Local Visual Diff Tool Design

## Goal

Build a local-only PHP 8.3 CLI application that compares two locally hosted versions of the same website.

### Phase 1 goal

- compare the homepage of the baseline site and the candidate site
- render both pages in a real browser
- capture screenshots
- compare them visually
- generate output images for changed pages
- write a static HTML report for the homepage result

### Phase 2 goal

- crawl the homepage of the baseline site
- extract same-origin links from that homepage
- map each baseline page to the candidate site by path
- run the same comparison pipeline for each discovered page
- append all page results to the same static HTML report

The tool must work against local development URLs and must not depend on external hosted services.

## Scope

### In scope for phase 1

- homepage comparison only
- path-based URL mapping from baseline base URL to candidate base URL for `/`
- full-page screenshots
- visual comparison with configurable shift tolerance
- report generation with Fluid templates
- local filesystem output

### Planned next scope for phase 2

- one-level crawl of links found on the baseline homepage
- same-origin page links only
- path-based URL mapping from baseline base URL to candidate base URL for discovered paths
- multi-page reporting using the same comparison pipeline

### Out of scope for phase 1

- crawling beyond the homepage itself
- recursive crawling beyond the homepage link set
- form interaction and authenticated flows
- JavaScript-driven navigation flows after page load
- cross-browser matrix comparison

### Out of scope for phase 2
- form interaction and authenticated flows
- JavaScript-driven navigation flows after page load
- cross-browser matrix comparison

## Explicit Non-Goals

The following are explicitly not part of this tool, not only out of scope for the first version:

- DOM diff reporting
- asset-by-asset comparison
- asset filename comparison
- CSS file comparison
- JavaScript bundle comparison

This tool is intentionally focused on rendered visual comparison only.

## Recommended Architecture

Use a Composer-based PHP CLI app with Brave as the rendering engine.

### Why Brave

- Brave is already installed locally.
- Brave is Chromium-based, so it supports the Chrome DevTools Protocol well.
- Full-page screenshot automation is simpler and more stable than a Firefox-first setup from PHP.
- No Selenium server is required for the first version.

### Main libraries

- `symfony/console` for CLI commands and options
- `symfony/dom-crawler` for HTML parsing and link extraction
- `symfony/http-client` or plain `curl` wrappers for fetching HTML
- `typo3fluid/fluid` for HTML report generation
- `symfony/config` is optional if config loading grows beyond simple INI parsing
- `chrome-php/chrome` for driving Brave through DevTools

### Image backend

At least one image-processing backend is required for screenshot comparison and overlay generation.

Preferred:

- MacPorts package `php83-imagick`, which provides `ext-imagick` and depends on `ImageMagick`

Fallback:

- local ImageMagick CLI via `magick`

The current local PHP build does not include `ext-imagick` or `ext-gd`, so image handling must be treated as an explicit prerequisite in implementation.

## High-Level Flow

### Phase 1 flow

1. User runs one CLI command with the baseline and candidate base URLs.
2. The tool creates a comparison queue containing only `/`.
3. For that path, the tool builds:
   - baseline URL = `baselineBaseUrl + path`
   - candidate URL = `candidateBaseUrl + path`
4. The tool opens both URLs in Brave with identical browser settings.
5. It waits for the page to settle, disables motion, and captures full-page screenshots.
6. The tool compares the two screenshots.
7. If there is a difference, it writes:
    - baseline screenshot
    - candidate screenshot
    - overlay image
    - highlighted diff image
8. If the page matches, it records the result in the report and only keeps screenshots when `capture_matching_screenshots` is enabled.
9. The tool renders a static HTML report using Fluid.

### Phase 2 flow

1. User runs the same command with crawling enabled.
2. The tool fetches the baseline homepage HTML.
3. The tool extracts valid same-origin links from the baseline homepage.
4. The tool adds the homepage itself to the comparison queue.
5. The tool normalizes, deduplicates, and sorts the paths.
6. For each path, it runs the same comparison pipeline already implemented in phase 1.
7. The tool renders a multi-page static HTML report using Fluid.

## Crawl Rules

This section applies to phase 2 and later. Phase 1 does not crawl links.

The crawler should operate only on links from the baseline homepage.

### Accept

- same-origin HTTP or HTTPS links
- relative links like `/about`
- links that resolve to the same host and port as the baseline base URL

### Reject

- external links
- `mailto:`
- `tel:`
- `javascript:`
- fragment-only links like `#top`
- non-HTML resources such as obvious image, CSS, JS, PDF, ZIP, and media file URLs

### Normalization

- strip URL fragments
- preserve query strings only if explicitly enabled in a later version
- normalize trailing slashes consistently
- deduplicate paths after normalization

For the first version, query strings should be ignored unless they are part of the base homepage URL itself. This keeps the crawl deterministic and avoids duplicate page variants.

## URL Mapping

The URL mapper should compare by path.

Example:

- baseline base URL: `http://legacy.local`
- candidate base URL: `http://upgraded.local`
- path: `/about`

Comparison pair:

- baseline: `http://legacy.local/about`
- candidate: `http://upgraded.local/about`

This rule is simple, deterministic, and matches the user requirement.

### Base URL normalization

The tool should normalize user-provided base URLs before path mapping.

Rules:

- accept base URLs with or without a trailing slash
- remove a trailing slash from the stored base URL unless the URL is just the origin root
- preserve scheme, host, and port

Examples:

- `http://legacy.local` stays `http://legacy.local`
- `http://legacy.local/` becomes `http://legacy.local`
- `http://legacy.local:8080/` becomes `http://legacy.local:8080`

This avoids accidental double slashes and ensures consistent path joining.

## CLI Interface

Recommended command:

```bash
php bin/visual-diff http://legacy.local http://upgraded.local
```

### Configuration source order

Runtime settings should be resolved in this order:

1. explicit CLI options
2. `config/local.ini`
3. `config/defaults.ini`
4. application hardcoded fallback defaults

This makes defaults easy to adjust without changing PHP code, while still allowing one-off overrides from the command line.

### Recommended config file

```text
config/defaults.ini
```

An optional machine-specific override file should also be supported:

```text
config/local.ini
```

That file should be gitignored and loaded after `config/defaults.ini`.

Recommended options:

- `--output-dir`
- `--browser-path=/Applications/Brave Browser.app/Contents/MacOS/Brave Browser`
- `--viewport-width=1440`
- `--viewport-height=900`
- `--settle-ms=1000`
- `--timeout-ms=30000`
- `--max-fullpage-height=0`
- `--context-padding=200`
- `--shift-tolerance=0`
- `--color-threshold=0`
- `--crawl-homepage-links=0`
- `--max-pages=0`
- `--capture-matching-screenshots=0`
- `--overwrite`

Required positional arguments:

- `baseline-base-url`
- `candidate-base-url`

### Option behavior

- `--shift-tolerance=0` means pixel-perfect layout matching.
- `--shift-tolerance=3` means small local offsets up to 3 pixels should be tolerated.
- `--crawl-homepage-links=0` means compare only `/`.
- `--crawl-homepage-links=1` means compare `/` plus same-origin links found on the baseline homepage.
- `--max-pages=0` means no explicit page cap after deduplication.
- `--max-fullpage-height=0` means there is no explicit page-height limit before fallback logic is considered.
- `--max-fullpage-height=<n>` means pages taller than `n` pixels should switch to the large-page fallback strategy.
- `--context-padding=200` means focused fallback artifacts should include 200 pixels of surrounding context above and below the detected changed region.
- `--color-threshold` can be used later to tolerate minor antialiasing differences; default should remain strict.
- `--capture-matching-screenshots=0` means matching pages are still listed in the report, but screenshot files are not kept by default.
- `--capture-matching-screenshots=1` means matching pages also keep baseline and candidate screenshots.
- if `--output-dir` is omitted, the tool should create a timestamped run directory under `var/results`, for example `var/results/run-20260331-103000`

### Recommended INI defaults

The first version should move all recommended runtime defaults into an INI file so they can be changed outside application code.

Example:

```ini
[browser]
path = "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser"
viewport_width = 1440
viewport_height = 900
settle_ms = 1000
timeout_ms = 30000
max_fullpage_height = 0
context_padding = 200

[compare]
shift_tolerance = 0
color_threshold = 0
overlay_opacity = 0.5

[crawl]
crawl_homepage_links = false
max_pages = 0

[report]
output_dir = "var/results"

[run]
capture_matching_screenshots = false
overwrite = false
```

### Config rules

- base URLs should still be provided explicitly by CLI in the first version
- browser and comparison defaults should come from the INI file
- CLI options should always override INI values for that run
- invalid or missing config values should fail early with a clear message
- `config/defaults.ini` should be committed to the repository and serve as the baseline shared defaults file
- `config/local.ini` should be optional and intended for machine-specific overrides such as browser path, OS-specific executable names, and local output preferences
- `report.output_dir` should be treated as the parent results directory
- when no explicit `--output-dir` is provided, the final run directory should be generated inside that parent directory using the current local timestamp
- config precedence should therefore be:
  - CLI options
  - `config/local.ini`
  - `config/defaults.ini`
  - hardcoded fallback values

### Browser discovery

The tool should support this browser resolution order:

1. explicit CLI browser path
2. `config/local.ini`
3. `config/defaults.ini`
4. common OS-specific defaults for the current platform

Examples:

- macOS:
  - `/Applications/Brave Browser.app/Contents/MacOS/Brave Browser`
  - `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`
  - `/Applications/Chromium.app/Contents/MacOS/Chromium`
- Linux:
  - `/usr/bin/brave-browser`
  - `/usr/bin/google-chrome`
  - `/usr/bin/chromium`
  - `/usr/bin/chromium-browser`

If no working browser is found, the run should fail before comparison starts.

## Browser Rendering Strategy

Each page should be rendered in a fresh browser tab with stable settings.

### Browser requirements

- fixed viewport for every page
- identical user agent for all captures
- JavaScript enabled
- motion suppressed before capture
- consistent background color
- full-page PNG screenshot output

### Large-page fallback

Very tall pages can produce extremely large screenshots that are slow to capture, expensive to compare, or too memory-heavy to process comfortably.

The tool should not solve this by globally downscaling the full page, because that would make small layout shifts harder to detect.

Recommended fallback behavior:

1. Attempt normal full-page capture when the page size is within configured limits.
2. If the rendered page exceeds `max_fullpage_height`, switch to segmented capture.
3. Compare the page in vertical segments of fixed height.
4. If a segment differs, generate a focused artifact for the changed area with extra surrounding context.
5. Include enough space above and below the changed region to make the location understandable when reviewed manually.

This preserves review usefulness without forcing the report to carry enormous images for every long page.

### Rendering consistency note

The documentation and report should explicitly state that the most reliable results come from rendering both sites on the same machine with the same installed fonts and rendering stack.

Visual differences can appear because of:

- installed fonts
- font fallback behavior
- browser version
- operating system rendering

The first version should document this clearly rather than trying to normalize those variables automatically.

### Stabilization steps before screenshot

1. Navigate to the page.
2. Wait for network idle or a configurable timeout.
3. Inject CSS that disables:
   - animations
   - transitions
   - caret blinking
   - smooth scrolling
4. Wait `settle-ms`.
5. Capture the full page.

Suggested injected CSS:

```css
*,
*::before,
*::after {
  animation: none !important;
  transition: none !important;
  caret-color: transparent !important;
  scroll-behavior: auto !important;
}
```

This reduces false positives from animation or transition timing.

## Comparison Strategy

The comparison should produce two outputs:

- a boolean pass/fail result
- visual artifacts for report inspection

### Artifacts

- `overlay.png`
- `diff.png`
- `context.png`, when large-page fallback is used

When a page is different, the tool should also keep:

- `baseline.png`
- `candidate.png`

When a page is matching, the tool should keep `baseline.png` and `candidate.png` only if matching screenshot capture is enabled.

When large-page fallback is used, the primary review artifact may be `context.png` instead of a single full-page overlay. That context image should show the changed region with configurable padding so a human reviewer can understand where on the page the difference occurred.

### Overlay image

The overlay image should place the candidate screenshot above the baseline screenshot using configurable alpha blending.

Recommended default:

- baseline at 100%
- candidate at 50% opacity

This makes shifted elements visibly double-exposed.

### Highlighted diff image

The diff image should mark changed regions clearly, for example:

- unchanged pixels dimmed
- changed pixels highlighted in red

This is easier to scan than the overlay alone.

### Context artifact

When the page is too large for practical full-page review, the tool should generate a focused context artifact around the changed area.

Rules:

- include the changed region
- include configurable padding before and after the region
- preserve the page width so horizontal context is not lost
- prefer one artifact per changed segment in the first version, rather than trying to merge distant regions into one image

This keeps the fallback implementation understandable and still useful in the report.

## Shift Tolerance Design

This is the most important comparison rule beyond exact pixel matching.

### Requirement

Small layout drift such as 1 to 3 pixels should be optionally tolerated and not reported as a visual regression.

### Recommended algorithm

For each pixel in the candidate image:

1. Compare it directly to the same pixel in the baseline image.
2. If it matches within the color threshold, mark it unchanged.
3. If it does not match, search within a square neighborhood around the baseline pixel:
   - radius = `shift-tolerance`
4. If a matching source pixel is found in that radius, treat this as tolerated local movement.
5. If no acceptable match is found, mark it as a true diff.

### Notes

- `shift-tolerance=0` reduces to exact pixel comparison.
- This algorithm is intentionally local. It tolerates tiny movement but does not hide substantial layout changes.
- It is best suited to small alignment drift, font rasterization wobble, and box shifts.
- It should be applied symmetrically enough to avoid one-sided masking; implementation can compare both directions or compare on a generated difference map.

### Limits

- Large reflows should still fail.
- Text rewrapping across lines should still fail.
- Missing or added sections should still fail.

## Result Model

Each crawled page should produce a result record with fields like:

- `path`
- `baselineUrl`
- `candidateUrl`
- `status`
- `hasDiff`
- `httpStatus.baseline`
- `httpStatus.candidate`
- `pixelCount`
- `changedPixelCount`
- `diffRatio`
- `shiftTolerance`
- `artifacts.candidate`
- `artifacts.baseline`
- `artifacts.overlay`
- `artifacts.diff`
- `artifacts.context`
- `errorMessage`

`status` values:

- `matching`
- `different`
- `http_error`
- `runtime_error`
- `skipped`

In phase 1, there is only one result record for `/`. The model stays the same so phase 2 can reuse it unchanged.

## Output Directory Layout

Recommended output structure:

```text
var/results/<run-id>/
  report.html
  report.json
  assets/
    styles.css
  pages/
    home/
      baseline.png
      candidate.png
      overlay.png
      diff.png
      context.png
      result.json
    about/
      baseline.png
      candidate.png
      overlay.png
      diff.png
      context.png
      result.json
```

`baseline.png` and `candidate.png` are always present for different pages, and only present for matching pages when matching screenshot capture is enabled.

### Path-to-directory mapping

- `/` becomes `home`
- `/about` becomes `about`
- nested paths become safe filesystem slugs
- collisions should be handled deterministically, for example by appending a short hash

## HTML Report Design

Use Fluid for report rendering.

### Why Fluid fits

- it is a standalone PHP template engine
- it is sufficient for a static report
- it keeps presentation separate from crawl and compare logic

### Report sections

1. Run summary
   - baseline base URL
   - candidate base URL
   - total pages
   - matching pages
   - different pages
   - HTTP error pages
   - runtime error pages
   - shift tolerance used
   - run timestamp

2. Result table
   - status dot
   - text status
   - page title
   - baseline page link
   - candidate page link
   - baseline screenshot link
   - candidate screenshot link
   - diff artifact link
   - context artifact link

3. Optional error section
   - page path
   - message

In phase 1, the result table will contain a single row for `/`. This keeps the report format stable when phase 2 adds more pages.

### Result table details

The report should be a clean, readable HTML file centered around a single table.

Required columns:

- `Status`: a small visual status indicator
- `Result`: text such as `matching`, `different`, or `HTTP error 500`
- `Page title`: title extracted from the rendered page when available, otherwise a fallback such as the path
- `Baseline page`: link to the baseline site URL
- `Candidate page`: link to the candidate site URL
- `Baseline screenshot`: optional artifact link
- `Candidate screenshot`: optional artifact link
- `Diff`: link to the visual diff artifact when the page is different
- `Context`: link to the focused context artifact when large-page fallback is used

### Status indicator

The first column should use a circular status dot with a slightly dimensional appearance so it is easy to scan.

Color mapping:

- green: `matching`
- yellow: `different`
- red: `http_error`
- red: `runtime_error`

The styling should be clean and readable rather than decorative. The goal is fast scanning.

### Link labels

Prefer explicit link labels over vague terms like `first` and `second`.

Recommended labels:

- `Baseline`
- `Candidate`
- `Baseline shot`
- `Candidate shot`
- `Diff`

If the user later wants custom labels, those can be made configurable, but the first version should use clear fixed labels.

### Empty cells

Artifact cells may be empty when:

- the page matched and matching screenshot capture was disabled
- the page returned an HTTP error
- the page failed before screenshots could be produced

Empty cells should render as a subtle dash rather than raw blank space.

### Page title behavior

The report should display the page title for readability.

Rules:

- use the `<title>` text from the fetched page when available
- trim excessive whitespace
- if the title is empty or unavailable, fall back to the normalized path
- if both are unavailable, fall back to the full page URL

### Report styling

The report does not need to be visually elaborate, but it should look clean and intentional.

Usability priorities:

- table must be easy to scan
- column labels must be explicit
- status colors must be distinguishable
- long titles and URLs must not break layout badly
- rows should have comfortable spacing
- header row should stay visually distinct
- links should be obvious and clickable

Suggested styling approach:

- light background
- high-contrast text
- subtle borders
- sticky table header if practical
- monospace styling for paths or status details where useful
- sans-serif body text optimized for readability

### Template structure

- main report template
- partial for summary block
- partial for result row

The report should be fully static so it can be opened directly from disk in a browser.

## Documentation Style

The code should be written as production code that is also intentionally educational.

### Documentation requirements

- every class should have a docblock explaining its purpose and role in the system
- every public method should have a docblock describing inputs, outputs, and side effects
- non-obvious private methods should have docblocks where helpful
- comparison logic, browser automation steps, and config resolution should include step-by-step inline comments
- comments should explain why the code works this way, not only what the next line does
- templates and configuration files should contain comments where the format allows it

### Practical rule

The project should prefer more explanation than usual, especially in:

- image comparison code
- shift tolerance handling
- browser stabilization logic
- report generation flow
- configuration loading and precedence

This is an explicit requirement for this project because the user wants to learn from the codebase.

## Suggested Internal Structure

Recommended source layout:

```text
bin/
  visual-diff
config/
  defaults.ini
src/
  Command/
    CompareCommand.php
  Config/
    RunConfiguration.php
    IniConfigurationLoader.php
  Crawl/
    LinkExtractor.php
    UrlNormalizer.php
    UrlMapper.php
  Browser/
    BrowserFactory.php
    ScreenshotService.php
    PageStabilizer.php
  Compare/
    ImageComparator.php
    OverlayGenerator.php
    DiffImageGenerator.php
    ComparisonResult.php
  Report/
    ReportRenderer.php
    ResultSerializer.php
  Run/
    ComparisonRunner.php
    PageResult.php
  Support/
    Filesystem.php
    Slugger.php
templates/
  report/
    Report.html
    Partials/
      Summary.html
      ResultRow.html
```

### Responsibilities

- `CompareCommand`: parse CLI input and launch a run
- `IniConfigurationLoader`: load runtime defaults from `.ini`
- `LinkExtractor`: fetch and parse homepage links for phase 2
- `UrlNormalizer`: canonicalize links for deduplication
- `UrlMapper`: map baseline paths to candidate URLs
- `ScreenshotService`: drive Brave and save screenshots
- `ImageComparator`: detect whether screenshots differ
- `OverlayGenerator`: create overlay artifacts
- `DiffImageGenerator`: create highlight artifacts
- `ReportRenderer`: render `report.html` with Fluid
- `ComparisonRunner`: orchestrate the full workflow

### Phase boundary

The most important design rule is that page discovery must stay separate from page comparison.

- phase 1 page discovery returns only `/`
- phase 2 page discovery returns `/` plus homepage-derived paths
- the screenshot, diff, artifact, and report pipeline should not care where the path list came from

This separation is what makes the later expansion easy instead of requiring a rewrite.

## Error Handling

The tool should keep running when one page fails.

### Per-page HTTP failures should not abort the whole run

Examples:

- baseline page returns 500
- candidate page returns 403
- baseline page returns 404

Those pages should be marked as `http_error` and still appear in the report. The run should continue with the remaining queued pages.

### Per-page runtime failures should usually not abort the whole run

Examples:

- page timeout
- screenshot write failure
- image comparison failure

Those pages should be marked as `runtime_error` and still appear in the report unless the failure indicates the shared browser session itself is unusable.

### Whole-run failures should abort

Examples:

- invalid base URL
- Brave executable not found
- output directory not writable
- image backend missing
- cannot launch or communicate with the browser at startup

These should fail fast before crawling.

### Homepage crawl edge case

In phase 2, link discovery depends on successfully fetching the baseline homepage.

If the baseline homepage itself returns an HTTP error, the tool should:

- record the homepage result as `http_error`
- explain in the report that additional pages could not be discovered from the baseline homepage
- finish the run without attempting page discovery

## Performance

The first version should run sequentially.

Reasons:

- simpler debugging
- easier artifact correlation
- lower browser resource pressure
- fewer race conditions

Very tall pages should use the large-page fallback rather than trying to force one enormous full-page artifact through the entire pipeline.

Parallelism can be added later once the workflow is correct and stable.

## Testing Strategy

### Unit tests

- INI parsing and validation
- CLI override precedence over INI values
- URL normalization
- same-origin filtering
- path mapping
- path slug generation
- result serialization
- tolerance algorithm on small synthetic image fixtures

### Integration tests for phase 1

- compare two simple local fixture homepages
- verify report generation
- verify tolerated 1 to 3 pixel shifts
- verify larger shifts still fail
- verify matching pages are still listed in the report
- verify matching screenshots are omitted by default
- verify large-page fallback produces context artifacts with padding

### Integration tests for phase 2

- compare two simple local fixture sites
- verify homepage crawl
- verify only same-origin links are included
- verify deduplication and path mapping
- verify baseline homepage HTTP error prevents link discovery but still produces a report
- verify per-page HTTP errors are reported while the run continues

### Manual smoke test

Run against two local dev URLs and inspect:

- home page pair
- one identical page
- one slightly shifted page
- one intentionally broken page

## Prerequisites

Implementation should assume these local prerequisites:

- PHP 8.3
- Composer
- Brave installed
- one image backend:
  - MacPorts `php83-imagick`, preferred
  - or `magick` CLI available locally

## Recommended First Milestone

Build the first milestone around:

- homepage comparison only
- Brave full-page screenshot capture
- exact pixel diff
- overlay generation
- static HTML report via Fluid

Then add shift tolerance as the next milestone. After that, add homepage-link crawling as phase 2.

The reason for this order is simple:

- browser capture and report generation are the highest-risk pieces
- homepage-only comparison proves the core pipeline
- shift tolerance can then be added and tested in isolation
- homepage-link crawling becomes a page-discovery extension rather than a rewrite

## Final Recommendation

Proceed with:

- a PHP CLI app
- Brave via DevTools
- Fluid for report rendering
- static output artifacts on disk
- homepage-only comparison first
- exact comparison by default
- configurable `--shift-tolerance` for tolerated local movement
- matching pages listed in the report even when artifacts are omitted
- no `fail-on-diff` mode in the first version

This architecture is local-only, aligned with the current environment, and scoped tightly enough to build incrementally without unnecessary framework overhead.
