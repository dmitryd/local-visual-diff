# Visual Diff Tool Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a local-only PHP 8.3 CLI tool that compares a baseline and candidate website visually, writes image artifacts for changed pages, and generates a static HTML report.

**Architecture:** The tool is a Composer-based PHP CLI application. Symfony Console handles the command-line entry point, `chrome-php/chrome` drives Brave/Chromium locally for rendering, Imagick performs image comparison and artifact generation, and Fluid renders the static report. Phase 1 compares only the homepage, while the internals are designed around a queue of paths so phase 2 can add homepage link crawling without rewriting the comparison pipeline.

**Tech Stack:** PHP 8.3, Composer, Symfony Console, Symfony DomCrawler, Symfony CssSelector, Symfony HttpClient, TYPO3 Fluid, chrome-php/chrome, Imagick, PHPUnit

---

## File Map

### Runtime entry points

- Create: `bin/visual-diff`
- Create: `config/defaults.ini`

### Application bootstrap

- Create: `src/ApplicationFactory.php`
- Create: `src/Command/CompareCommand.php`

### Configuration

- Create: `src/Config/RunConfiguration.php`
- Create: `src/Config/IniConfigurationLoader.php`
- Create: `src/Config/ConfigurationMerger.php`

### URL and crawl logic

- Create: `src/Crawl/UrlNormalizer.php`
- Create: `src/Crawl/UrlMapper.php`
- Create: `src/Crawl/LinkExtractor.php`
- Create: `src/Crawl/PageQueueFactory.php`

### Browser capture

- Create: `src/Browser/BrowserLocator.php`
- Create: `src/Browser/BrowserFactory.php`
- Create: `src/Browser/PageStabilizer.php`
- Create: `src/Browser/ScreenshotService.php`
- Create: `src/Browser/PageSnapshot.php`

### Comparison and artifacts

- Create: `src/Compare/ComparisonResult.php`
- Create: `src/Compare/ImageComparator.php`
- Create: `src/Compare/OverlayGenerator.php`
- Create: `src/Compare/DiffImageGenerator.php`
- Create: `src/Compare/ContextArtifactGenerator.php`

### Reporting and orchestration

- Create: `src/Report/ReportRenderer.php`
- Create: `src/Report/ReportViewModelFactory.php`
- Create: `src/Run/PageResult.php`
- Create: `src/Run/RunSummary.php`
- Create: `src/Run/ComparisonRunner.php`

### Support utilities

- Create: `src/Support/Filesystem.php`
- Create: `src/Support/Slugger.php`
- Create: `src/Support/Clock.php`

### Templates

- Create: `templates/report/Report.html`
- Create: `templates/report/Partials/Summary.html`
- Create: `templates/report/Partials/ResultRow.html`

### Tests

- Create: `tests/Unit/Config/IniConfigurationLoaderTest.php`
- Create: `tests/Unit/Crawl/UrlNormalizerTest.php`
- Create: `tests/Unit/Crawl/UrlMapperTest.php`
- Create: `tests/Unit/Crawl/PageQueueFactoryTest.php`
- Create: `tests/Unit/Browser/BrowserLocatorTest.php`
- Create: `tests/Unit/Compare/ImageComparatorTest.php`
- Create: `tests/Unit/Report/ReportRendererTest.php`
- Create: `tests/Integration/CompareHomepageCommandTest.php`
- Create: `tests/Integration/CrawlHomepageLinksCommandTest.php`

### Tooling

- Create: `composer.json`
- Create: `phpunit.xml.dist`

## Task 1: Bootstrap The Project Skeleton

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `bin/visual-diff`
- Create: `src/ApplicationFactory.php`
- Create: `src/Command/CompareCommand.php`
- Test: `tests/Integration/CompareHomepageCommandTest.php`

- [ ] **Step 1: Write the failing CLI smoke test**

```php
<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use LocalVisualDiff\ApplicationFactory;

final class CompareHomepageCommandTest extends TestCase
{
    public function testCommandIsRegisteredWithPositionalUrls(): void
    {
        $application = ApplicationFactory::create();
        $command = $application->find('compare');

        self::assertSame('compare', $command->getName());
        self::assertStringContainsString('baseline-base-url', (string) $command->getDefinition());
        self::assertStringContainsString('candidate-base-url', (string) $command->getDefinition());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/CompareHomepageCommandTest.php -v`
Expected: FAIL because `ApplicationFactory` and `CompareCommand` do not exist yet.

- [ ] **Step 3: Write the bootstrap files**

```json
{
  "name": "local-visual-diff/tool",
  "type": "project",
  "require": {
    "php": "^8.3",
    "ext-curl": "*",
    "ext-dom": "*",
    "ext-json": "*",
    "ext-libxml": "*",
    "ext-mbstring": "*",
    "ext-imagick": "*",
    "chrome-php/chrome": "^1.14",
    "symfony/console": "^7.3",
    "symfony/css-selector": "^7.3",
    "symfony/dom-crawler": "^7.3",
    "symfony/http-client": "^7.3",
    "typo3fluid/fluid": "^5.2"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.5",
    "symfony/process": "^7.3"
  },
  "autoload": {
    "psr-4": {
      "LocalVisualDiff\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "LocalVisualDiff\\Tests\\": "tests/"
    }
  },
  "bin": [
    "bin/visual-diff"
  ]
}
```

```php
<?php

declare(strict_types=1);

namespace LocalVisualDiff;

use LocalVisualDiff\Command\CompareCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    /**
     * Create the console application and register the single default command.
     */
    public static function create(): Application
    {
        $application = new Application('local-visual-diff');
        $application->add(new CompareCommand());
        $application->setDefaultCommand('compare', true);

        return $application;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace LocalVisualDiff\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

final class CompareCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('compare')
            ->setDescription('Compare baseline and candidate websites visually.')
            ->addArgument('baseline-base-url', InputArgument::REQUIRED)
            ->addArgument('candidate-base-url', InputArgument::REQUIRED);
    }
}
```

- [ ] **Step 4: Run the smoke test**

Run: `vendor/bin/phpunit tests/Integration/CompareHomepageCommandTest.php -v`
Expected: PASS

- [ ] **Step 5: Install dependencies and verify the executable**

Run: `composer install`
Expected: dependencies install successfully and `vendor/` is created.

Run: `php bin/visual-diff --help`
Expected: Symfony help output showing positional arguments for `baseline-base-url` and `candidate-base-url`.

- [ ] **Step 6: Commit**

```bash
git add composer.json phpunit.xml.dist bin/visual-diff src/ApplicationFactory.php src/Command/CompareCommand.php tests/Integration/CompareHomepageCommandTest.php
git commit -m "chore: bootstrap visual diff CLI"
```

## Task 2: Add Config Loading And Base URL Normalization

**Files:**
- Create: `config/defaults.ini`
- Create: `src/Config/RunConfiguration.php`
- Create: `src/Config/IniConfigurationLoader.php`
- Create: `src/Config/ConfigurationMerger.php`
- Create: `src/Crawl/UrlNormalizer.php`
- Test: `tests/Unit/Config/IniConfigurationLoaderTest.php`
- Test: `tests/Unit/Crawl/UrlNormalizerTest.php`

- [ ] **Step 1: Write failing unit tests for config precedence and trailing-slash normalization**

```php
public function testDefaultsAndLocalIniAreMergedWithCliTakingPriority(): void
{
    $loader = new IniConfigurationLoader();

    $config = $loader->load(
        __DIR__ . '/fixtures/defaults.ini',
        __DIR__ . '/fixtures/local.ini',
        ['browser_path' => '/custom/browser']
    );

    self::assertSame('/custom/browser', $config->browserPath);
    self::assertSame(1440, $config->viewportWidth);
}

public function testBaseUrlNormalizationRemovesTrailingSlash(): void
{
    $normalizer = new UrlNormalizer();

    self::assertSame('http://legacy.local', $normalizer->normalizeBaseUrl('http://legacy.local/'));
    self::assertSame('http://legacy.local:8080', $normalizer->normalizeBaseUrl('http://legacy.local:8080/'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Config/IniConfigurationLoaderTest.php tests/Unit/Crawl/UrlNormalizerTest.php -v`
Expected: FAIL because the configuration and normalizer classes do not exist.

- [ ] **Step 3: Implement configuration loading and URL normalization**

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

```php
final class RunConfiguration
{
    public function __construct(
        public readonly string $baselineBaseUrl,
        public readonly string $candidateBaseUrl,
        public readonly ?string $outputDir,
        public readonly string $browserPath,
        public readonly int $viewportWidth,
        public readonly int $viewportHeight,
        public readonly int $settleMs,
        public readonly int $timeoutMs,
        public readonly int $maxFullpageHeight,
        public readonly int $contextPadding,
        public readonly int $shiftTolerance,
        public readonly int $colorThreshold,
        public readonly bool $crawlHomepageLinks,
        public readonly int $maxPages,
        public readonly bool $captureMatchingScreenshots,
        public readonly bool $overwrite
    ) {
    }
}
```

```php
final class UrlNormalizer
{
    /**
     * Normalize base URLs so path joins remain predictable.
     */
    public function normalizeBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
```

- [ ] **Step 4: Run unit tests**

Run: `vendor/bin/phpunit tests/Unit/Config/IniConfigurationLoaderTest.php tests/Unit/Crawl/UrlNormalizerTest.php -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/defaults.ini src/Config/RunConfiguration.php src/Config/IniConfigurationLoader.php src/Config/ConfigurationMerger.php src/Crawl/UrlNormalizer.php tests/Unit/Config/IniConfigurationLoaderTest.php tests/Unit/Crawl/UrlNormalizerTest.php
git commit -m "feat: add config loading and URL normalization"
```

## Task 3: Add Browser Discovery And Homepage Screenshot Capture

**Files:**
- Create: `src/Browser/BrowserLocator.php`
- Create: `src/Browser/BrowserFactory.php`
- Create: `src/Browser/PageStabilizer.php`
- Create: `src/Browser/PageSnapshot.php`
- Create: `src/Browser/ScreenshotService.php`
- Test: `tests/Unit/Browser/BrowserLocatorTest.php`

- [ ] **Step 1: Write the failing browser locator test**

```php
public function testMacOsFallbackPathsAreCheckedInOrder(): void
{
    $locator = new BrowserLocator(['/Applications/Brave Browser.app/Contents/MacOS/Brave Browser']);

    self::assertSame(
        '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
        $locator->resolve(null, 'Darwin')
    );
}
```

- [ ] **Step 2: Run the locator test**

Run: `vendor/bin/phpunit tests/Unit/Browser/BrowserLocatorTest.php -v`
Expected: FAIL because `BrowserLocator` does not exist.

- [ ] **Step 3: Implement browser discovery and screenshot capture abstractions**

```php
final class BrowserLocator
{
    public function __construct(private readonly array $existingPaths = [])
    {
    }

    public function resolve(?string $configuredPath, string $osFamily): string
    {
        $candidates = array_filter([
            $configuredPath,
            ...$this->defaultCandidatesFor($osFamily),
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not find a supported Chromium-based browser.');
    }
}
```

```php
final class PageSnapshot
{
    public function __construct(
        public readonly string $title,
        public readonly int $httpStatus,
        public readonly string $imagePath,
        public readonly int $pageHeight
    ) {
    }
}
```

```php
final class PageStabilizer
{
    public const DISABLE_MOTION_CSS = <<<'CSS'
*,
*::before,
*::after {
  animation: none !important;
  transition: none !important;
  caret-color: transparent !important;
  scroll-behavior: auto !important;
}
CSS;
}
```

- [ ] **Step 4: Run the locator test**

Run: `vendor/bin/phpunit tests/Unit/Browser/BrowserLocatorTest.php -v`
Expected: PASS

- [ ] **Step 5: Manual browser smoke test**

Run: `php bin/visual-diff http://legacy.local http://upgraded.local --capture-matching-screenshots=1`
Expected: the command reaches browser startup and writes placeholder output or a controlled runtime error if later tasks are not yet implemented.

- [ ] **Step 6: Commit**

```bash
git add src/Browser/BrowserLocator.php src/Browser/BrowserFactory.php src/Browser/PageStabilizer.php src/Browser/PageSnapshot.php src/Browser/ScreenshotService.php tests/Unit/Browser/BrowserLocatorTest.php
git commit -m "feat: add browser discovery and capture services"
```

## Task 4: Implement Imagick Comparison And Diff Artifacts

**Files:**
- Create: `src/Compare/ComparisonResult.php`
- Create: `src/Compare/ImageComparator.php`
- Create: `src/Compare/OverlayGenerator.php`
- Create: `src/Compare/DiffImageGenerator.php`
- Create: `src/Compare/ContextArtifactGenerator.php`
- Test: `tests/Unit/Compare/ImageComparatorTest.php`

- [ ] **Step 1: Write failing comparator tests for exact match and shift tolerance**

```php
public function testIdenticalImagesAreMatching(): void
{
    $comparator = new ImageComparator();
    $result = $comparator->compare('tests/fixtures/images/a.png', 'tests/fixtures/images/a.png', 0, 0);

    self::assertSame('matching', $result->status);
    self::assertFalse($result->hasDiff);
}

public function testSmallShiftIsToleratedWhenConfigured(): void
{
    $comparator = new ImageComparator();
    $result = $comparator->compare('tests/fixtures/images/base.png', 'tests/fixtures/images/shifted-by-2.png', 2, 0);

    self::assertSame('matching', $result->status);
}
```

- [ ] **Step 2: Run the comparator tests**

Run: `vendor/bin/phpunit tests/Unit/Compare/ImageComparatorTest.php -v`
Expected: FAIL because the comparator classes do not exist yet.

- [ ] **Step 3: Implement the comparison result and artifact generators**

```php
final class ComparisonResult
{
    public function __construct(
        public readonly string $status,
        public readonly bool $hasDiff,
        public readonly int $pixelCount,
        public readonly int $changedPixelCount,
        public readonly float $diffRatio,
        public readonly array $artifacts = []
    ) {
    }
}
```

```php
final class ImageComparator
{
    /**
     * Compare two rendered screenshots and treat small local movement as matching
     * when it falls inside the configured shift tolerance window.
     */
    public function compare(string $baselinePath, string $candidatePath, int $shiftTolerance, int $colorThreshold): ComparisonResult
    {
        // Load images with Imagick, walk pixels, track changes, and hand off
        // artifact generation to dedicated collaborators.
    }
}
```

```php
final class ContextArtifactGenerator
{
    /**
     * Build a context image around one changed segment when the page is too tall
     * for a practical single full-page review artifact.
     */
    public function generate(string $baselinePath, string $candidatePath, int $startY, int $endY, int $padding): string
    {
        // Crop the region with padding and write context.png.
    }
}
```

- [ ] **Step 4: Run the comparator tests**

Run: `vendor/bin/phpunit tests/Unit/Compare/ImageComparatorTest.php -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Compare/ComparisonResult.php src/Compare/ImageComparator.php src/Compare/OverlayGenerator.php src/Compare/DiffImageGenerator.php src/Compare/ContextArtifactGenerator.php tests/Unit/Compare/ImageComparatorTest.php
git commit -m "feat: add screenshot comparison and diff artifacts"
```

## Task 5: Render The Static Fluid Report

**Files:**
- Create: `src/Report/ReportRenderer.php`
- Create: `src/Report/ReportViewModelFactory.php`
- Create: `templates/report/Report.html`
- Create: `templates/report/Partials/Summary.html`
- Create: `templates/report/Partials/ResultRow.html`
- Test: `tests/Unit/Report/ReportRendererTest.php`

- [ ] **Step 1: Write the failing report rendering test**

```php
public function testReportContainsStatusLabelsAndBaselineCandidateLinks(): void
{
    $renderer = new ReportRenderer(__DIR__ . '/../../../templates/report');

    $html = $renderer->render([
        'summary' => ['matching' => 1, 'different' => 1, 'http_error' => 0, 'runtime_error' => 0],
        'results' => [[
            'status' => 'different',
            'statusLabel' => 'Different',
            'title' => 'Home',
            'path' => '/',
            'baselineUrl' => 'http://legacy.local',
            'candidateUrl' => 'http://upgraded.local',
            'artifacts' => ['diff' => 'pages/home/diff.png'],
        ]],
    ]);

    self::assertStringContainsString('Baseline', $html);
    self::assertStringContainsString('Candidate', $html);
    self::assertStringContainsString('Different', $html);
}
```

- [ ] **Step 2: Run the renderer test**

Run: `vendor/bin/phpunit tests/Unit/Report/ReportRendererTest.php -v`
Expected: FAIL because the renderer and templates do not exist.

- [ ] **Step 3: Implement the renderer and templates**

```php
final class ReportRenderer
{
    public function __construct(private readonly string $templateRoot)
    {
    }

    public function render(array $viewModel): string
    {
        // Create a Fluid view, assign variables, and render Report.html.
    }
}
```

```html
<table class="results-table">
    <thead>
        <tr>
            <th>Status</th>
            <th>Result</th>
            <th>Page title</th>
            <th>Baseline</th>
            <th>Candidate</th>
            <th>Baseline shot</th>
            <th>Candidate shot</th>
            <th>Diff</th>
            <th>Context</th>
        </tr>
    </thead>
    <tbody>
        <f:for each="{results}" as="result">
            <f:render partial="ResultRow" arguments="{result: result}" />
        </f:for>
    </tbody>
</table>
```

- [ ] **Step 4: Run the renderer test**

Run: `vendor/bin/phpunit tests/Unit/Report/ReportRendererTest.php -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Report/ReportRenderer.php src/Report/ReportViewModelFactory.php templates/report/Report.html templates/report/Partials/Summary.html templates/report/Partials/ResultRow.html tests/Unit/Report/ReportRendererTest.php
git commit -m "feat: add static HTML report rendering"
```

## Task 6: Wire Phase 1 Homepage Comparison End To End

**Files:**
- Modify: `src/Command/CompareCommand.php`
- Create: `src/Run/PageResult.php`
- Create: `src/Run/RunSummary.php`
- Create: `src/Run/ComparisonRunner.php`
- Create: `src/Support/Filesystem.php`
- Create: `src/Support/Slugger.php`
- Create: `src/Support/Clock.php`
- Test: `tests/Integration/CompareHomepageCommandTest.php`

- [ ] **Step 1: Expand the integration test to assert generated run directories**

```php
use Symfony\Component\Process\Process;

public function testHomepageRunCreatesTimestampedReportDirectory(): void
{
    $process = new Process(['php', 'bin/visual-diff', 'http://legacy.local', 'http://upgraded.local']);
    $process->run();

    self::assertTrue($process->isSuccessful());
    self::assertFileExists(__DIR__ . '/../../var/results');
}
```

- [ ] **Step 2: Run the integration test**

Run: `vendor/bin/phpunit tests/Integration/CompareHomepageCommandTest.php -v`
Expected: FAIL because the command does not execute the run yet.

- [ ] **Step 3: Implement the runner and the default output directory logic**

```php
final class ComparisonRunner
{
    /**
     * Phase 1 always compares the homepage by constructing a queue with a single `/` path.
     */
    public function run(RunConfiguration $configuration): RunSummary
    {
        $paths = ['/'];

        // Capture both pages, compare them, save artifacts, build the report view model,
        // render report.html, and write report.json.
    }
}
```

```php
final class Clock
{
    public function timestampForRunDirectory(): string
    {
        return date('Ymd-His');
    }
}
```

- [ ] **Step 4: Run the integration test**

Run: `vendor/bin/phpunit tests/Integration/CompareHomepageCommandTest.php -v`
Expected: PASS

- [ ] **Step 5: Manual smoke test**

Run: `php bin/visual-diff http://legacy.local http://upgraded.local`
Expected: a new directory like `var/results/run-20260331-103000` appears with `report.html`, `report.json`, and page artifacts for `/` as needed.

- [ ] **Step 6: Commit**

```bash
git add src/Command/CompareCommand.php src/Run/PageResult.php src/Run/RunSummary.php src/Run/ComparisonRunner.php src/Support/Filesystem.php src/Support/Slugger.php src/Support/Clock.php tests/Integration/CompareHomepageCommandTest.php
git commit -m "feat: add homepage comparison workflow"
```

## Task 7: Add Phase 2 Homepage Link Crawling

**Files:**
- Create: `src/Crawl/UrlMapper.php`
- Create: `src/Crawl/LinkExtractor.php`
- Create: `src/Crawl/PageQueueFactory.php`
- Test: `tests/Unit/Crawl/UrlMapperTest.php`
- Test: `tests/Unit/Crawl/PageQueueFactoryTest.php`
- Test: `tests/Integration/CrawlHomepageLinksCommandTest.php`

- [ ] **Step 1: Write failing crawl tests**

```php
public function testHomepageLinksProduceQueueWithHomepageAndSameOriginPaths(): void
{
    $factory = new PageQueueFactory(new LinkExtractor(), new UrlNormalizer(), new UrlMapper());

    $paths = $factory->build('http://legacy.local', true, 0);

    self::assertContains('/', $paths);
    self::assertContains('/about', $paths);
    self::assertNotContains('https://twitter.com/example', $paths);
}
```

- [ ] **Step 2: Run the crawl tests**

Run: `vendor/bin/phpunit tests/Unit/Crawl/UrlMapperTest.php tests/Unit/Crawl/PageQueueFactoryTest.php tests/Integration/CrawlHomepageLinksCommandTest.php -v`
Expected: FAIL because crawl services do not exist yet.

- [ ] **Step 3: Implement homepage-only link discovery**

```php
final class LinkExtractor
{
    /**
     * Fetch the baseline homepage HTML and return only same-origin navigational links.
     */
    public function extract(string $baselineBaseUrl): array
    {
        // Use Symfony HttpClient to fetch HTML and DomCrawler to parse anchors.
    }
}
```

```php
final class PageQueueFactory
{
    public function build(string $baselineBaseUrl, bool $crawlHomepageLinks, int $maxPages): array
    {
        if ($crawlHomepageLinks === false) {
            return ['/'];
        }

        // Start with `/`, merge normalized homepage links, deduplicate, sort, and apply maxPages.
    }
}
```

- [ ] **Step 4: Run the crawl tests**

Run: `vendor/bin/phpunit tests/Unit/Crawl/UrlMapperTest.php tests/Unit/Crawl/PageQueueFactoryTest.php tests/Integration/CrawlHomepageLinksCommandTest.php -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Crawl/UrlMapper.php src/Crawl/LinkExtractor.php src/Crawl/PageQueueFactory.php tests/Unit/Crawl/UrlMapperTest.php tests/Unit/Crawl/PageQueueFactoryTest.php tests/Integration/CrawlHomepageLinksCommandTest.php
git commit -m "feat: add homepage link crawling"
```

## Task 8: Add Large-Page Fallback And Final Error Handling Rules

**Files:**
- Modify: `src/Browser/ScreenshotService.php`
- Modify: `src/Compare/ImageComparator.php`
- Modify: `src/Compare/ContextArtifactGenerator.php`
- Modify: `src/Run/ComparisonRunner.php`
- Modify: `src/Report/ReportViewModelFactory.php`
- Test: `tests/Unit/Compare/ImageComparatorTest.php`
- Test: `tests/Integration/CompareHomepageCommandTest.php`

- [ ] **Step 1: Add the failing large-page fallback test**

```php
public function testLargePageFallbackProducesContextArtifact(): void
{
    $comparator = new ImageComparator();

    $result = $comparator->compareLargePage(
        'tests/fixtures/images/tall-baseline.png',
        'tests/fixtures/images/tall-candidate.png',
        0,
        0,
        3000,
        200
    );

    self::assertArrayHasKey('context', $result->artifacts);
}
```

- [ ] **Step 2: Run the fallback test**

Run: `vendor/bin/phpunit tests/Unit/Compare/ImageComparatorTest.php -v`
Expected: FAIL because the large-page fallback is not implemented yet.

- [ ] **Step 3: Implement segmented comparison with context artifacts**

```php
public function compareLargePage(
    string $baselinePath,
    string $candidatePath,
    int $shiftTolerance,
    int $colorThreshold,
    int $maxFullpageHeight,
    int $contextPadding
): ComparisonResult {
    // Split the tall page into vertical segments, compare segment by segment,
    // and write one context artifact per changed segment in the first version.
}
```

- [ ] **Step 4: Run the comparator and integration suites**

Run: `vendor/bin/phpunit tests/Unit/Compare/ImageComparatorTest.php tests/Integration/CompareHomepageCommandTest.php tests/Integration/CrawlHomepageLinksCommandTest.php -v`
Expected: PASS

- [ ] **Step 5: Manual failure-policy smoke tests**

Run: `php bin/visual-diff http://legacy.local http://upgraded.local --crawl-homepage-links=1`
Expected: browser startup failures abort the run; per-page HTTP errors are listed in the report and the run continues.

- [ ] **Step 6: Commit**

```bash
git add src/Browser/ScreenshotService.php src/Compare/ImageComparator.php src/Compare/ContextArtifactGenerator.php src/Run/ComparisonRunner.php src/Report/ReportViewModelFactory.php tests/Unit/Compare/ImageComparatorTest.php tests/Integration/CompareHomepageCommandTest.php tests/Integration/CrawlHomepageLinksCommandTest.php
git commit -m "feat: add large-page fallback and final run semantics"
```

## Self-Review Checklist

- [ ] Confirm every approved spec requirement is covered by at least one task.
- [ ] Confirm Composer requirements include PHP 8.3 and all required PHP extensions.
- [ ] Confirm `baseline` and `candidate` naming is consistent across code, config, artifacts, and report output.
- [ ] Confirm `config/defaults.ini` is tracked and `config/local.ini` remains gitignored.
- [ ] Confirm timestamped output directory creation is covered.
- [ ] Confirm matching pages are listed in the report even when screenshots are omitted.
- [ ] Confirm DOM and asset comparison are not reintroduced anywhere in the plan.
