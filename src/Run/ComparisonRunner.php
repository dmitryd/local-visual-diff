<?php

declare(strict_types=1);

namespace LocalVisualDiff\Run;

use LocalVisualDiff\Browser\ScreenshotService;
use LocalVisualDiff\Crawl\PageQueueFactory;
use LocalVisualDiff\Compare\ImageComparator;
use LocalVisualDiff\Config\RunConfiguration;
use LocalVisualDiff\Report\ReportRenderer;
use LocalVisualDiff\Report\ReportViewModelFactory;
use LocalVisualDiff\Support\ArtifactImageWriter;
use LocalVisualDiff\Support\Clock;
use LocalVisualDiff\Support\Filesystem;
use LocalVisualDiff\Support\RunProfiler;
use LocalVisualDiff\Support\Slugger;
use RuntimeException;
use Throwable;

/**
 * Orchestrate one visual comparison run.
 *
 * The runner executes one comparison pipeline per normalized page target. That
 * lets homepage-only runs and recursive crawl runs share the same comparison,
 * artifact, and reporting code.
 */
final class ComparisonRunner
{
    public function __construct(
        private readonly ScreenshotService $screenshotService = new ScreenshotService(),
        private readonly ImageComparator $imageComparator = new ImageComparator(),
        private readonly PageQueueFactory $pageQueueFactory = new PageQueueFactory(),
        private readonly ReportViewModelFactory $reportViewModelFactory = new ReportViewModelFactory(),
        private readonly ReportRenderer $reportRenderer = new ReportRenderer(),
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly Slugger $slugger = new Slugger(),
        private readonly Clock $clock = new Clock()
    ) {
    }

    public function run(RunConfiguration $configuration): RunSummary
    {
        $profiler = new RunProfiler($configuration->profile);
        $outputDir = $this->resolveOutputDirectory($configuration->outputDir);
        $this->filesystem->ensureDirectory($outputDir);

        $generatedAt = $this->clock->formattedNow();
        $pageResults = [];
        $homepageResult = $this->comparePage($configuration, $outputDir, '/', $profiler);
        $this->logPageResult($configuration, $homepageResult);
        $pageResults[] = $homepageResult;

        if ($this->shouldCrawlAdditionalPages($configuration, $homepageResult)) {
            $paths = $profiler->measure(
                'queue_build',
                fn (): array => $this->pageQueueFactory->build(
                    $configuration->baselineBaseUrl,
                    true,
                    $configuration->ignoredQueryParamNamePattern,
                    $configuration->crawlFetchVerifyTls,
                    $configuration->maxPages
                )
            );

            foreach ($paths as $path) {
                if ($path === '/') {
                    continue;
                }

                $pageResult = $this->comparePage($configuration, $outputDir, $path, $profiler);
                $this->logPageResult($configuration, $pageResult);
                $pageResults[] = $pageResult;
            }
        }

        $reportPath = $this->filesystem->joinPath($outputDir, 'report.html');
        $reportJsonPath = $this->filesystem->joinPath($outputDir, 'report.json');
        $summary = $this->buildSummary($configuration, $generatedAt, $outputDir, $reportPath, $reportJsonPath, $pageResults);

        $viewModel = $this->reportViewModelFactory->create(
            $summary->toArray(),
            array_map(static fn (PageResult $pageResult): array => $pageResult->toArray(), $pageResults)
        );

        $profiler->measure('report_write', function () use ($reportPath, $reportJsonPath, $viewModel): void {
            $this->filesystem->writeFile($reportPath, $this->reportRenderer->render($viewModel));
            $this->filesystem->writeJson($reportJsonPath, $viewModel);
        });

        $this->logProfile($configuration, $profiler);

        return $summary;
    }

    /**
     * Decide whether the run should expand from the homepage result into the
     * crawl queue.
     */
    private function shouldCrawlAdditionalPages(RunConfiguration $configuration, PageResult $homepageResult): bool
    {
        if (!$configuration->crawlHomepageLinks) {
            return false;
        }

        if ($configuration->maxPages === 1) {
            return false;
        }

        return !in_array($homepageResult->status, ['http_error', 'runtime_error'], true);
    }

    private function comparePage(RunConfiguration $configuration, string $outputDir, string $path, RunProfiler $profiler): PageResult
    {
        $pageSlug = $this->slugger->slugPath($path);
        $pageDir = $this->filesystem->joinPath($outputDir, 'pages', $pageSlug);
        $this->filesystem->ensureDirectory($pageDir);
        $artifactImageWriter = new ArtifactImageWriter($configuration->artifactImageFormat);
        $imageComparator = $this->imageComparatorFor($configuration->artifactImageFormat);

        $baselineUrl = $this->joinBaseUrlAndPath($configuration->baselineBaseUrl, $path);
        $candidateUrl = $this->joinBaseUrlAndPath($configuration->candidateBaseUrl, $path);
        $baselineScreenshotPath = $this->filesystem->joinPath($pageDir, 'baseline.capture.png');
        $candidateScreenshotPath = $this->filesystem->joinPath($pageDir, 'candidate.capture.png');

        try {
            $baselineSnapshot = $profiler->measure(
                'capture_baseline',
                fn () => $this->screenshotService->capture(
                    $baselineUrl,
                    $baselineScreenshotPath,
                    $configuration->viewportWidth,
                    $configuration->viewportHeight,
                    $configuration->timeoutMs,
                    $configuration->settleMs,
                    $configuration->acceptLanguage,
                    $configuration->browserPath,
                    PHP_OS_FAMILY
                )
            );
            $candidateSnapshot = $profiler->measure(
                'capture_candidate',
                fn () => $this->screenshotService->capture(
                    $candidateUrl,
                    $candidateScreenshotPath,
                    $configuration->viewportWidth,
                    $configuration->viewportHeight,
                    $configuration->timeoutMs,
                    $configuration->settleMs,
                    $configuration->acceptLanguage,
                    $configuration->browserPath,
                    PHP_OS_FAMILY
                )
            );
        } catch (Throwable $throwable) {
            $this->filesystem->removeFileIfExists($baselineScreenshotPath);
            $this->filesystem->removeFileIfExists($candidateScreenshotPath);
            $this->filesystem->removeDirectoryIfEmpty($pageDir);

            return new PageResult(
                $path,
                $this->fallbackTitle($path, $baselineUrl, $candidateUrl),
                'runtime_error',
                $baselineUrl,
                $candidateUrl,
                0,
                0,
                []
            );
        }

        $title = $this->bestTitle($baselineSnapshot->title, $candidateSnapshot->title, $path, $baselineUrl, $candidateUrl);

        $this->logSnapshotDiagnostics($configuration, 'Baseline', $baselineSnapshot);
        $this->logSnapshotDiagnostics($configuration, 'Candidate', $candidateSnapshot);

        if ($baselineSnapshot->httpStatus >= 400 || $candidateSnapshot->httpStatus >= 400) {
            $this->filesystem->removeFileIfExists($baselineScreenshotPath);
            $this->filesystem->removeFileIfExists($candidateScreenshotPath);
            $this->filesystem->removeDirectoryIfEmpty($pageDir);

            return new PageResult(
                $path,
                $title,
                'http_error',
                $baselineUrl,
                $candidateUrl,
                $baselineSnapshot->httpStatus,
                $candidateSnapshot->httpStatus,
                []
            );
        }

        try {
            $pageHeight = max($baselineSnapshot->pageHeight, $candidateSnapshot->pageHeight);
            $comparison = $profiler->measure(
                'compare_images',
                fn () => $imageComparator->compare(
                    $baselineScreenshotPath,
                    $candidateScreenshotPath,
                    $configuration->shiftTolerance,
                    $configuration->colorThreshold,
                    $pageDir,
                    $pageHeight,
                    $configuration->maxFullpageHeight,
                    $configuration->contextPadding
                )
            );
        } catch (Throwable $throwable) {
            $this->filesystem->removeFileIfExists($baselineScreenshotPath);
            $this->filesystem->removeFileIfExists($candidateScreenshotPath);
            $this->filesystem->removeDirectoryIfEmpty($pageDir);

            return new PageResult(
                $path,
                $title,
                'runtime_error',
                $baselineUrl,
                $candidateUrl,
                $baselineSnapshot->httpStatus,
                $candidateSnapshot->httpStatus,
                []
            );
        }

        $artifacts = $this->relativeArtifacts($outputDir, $comparison->artifacts);

        if ($comparison->hasDiff) {
            $this->filesystem->removeFileIfExists($baselineScreenshotPath);
            $this->filesystem->removeFileIfExists($candidateScreenshotPath);

            return new PageResult(
                $path,
                $title,
                'different',
                $baselineUrl,
                $candidateUrl,
                $baselineSnapshot->httpStatus,
                $candidateSnapshot->httpStatus,
                $artifacts
            );
        }

        if (!$configuration->captureMatchingScreenshots) {
            $this->filesystem->removeFileIfExists($baselineScreenshotPath);
            $this->filesystem->removeFileIfExists($candidateScreenshotPath);
            $this->filesystem->removeDirectoryIfEmpty($pageDir);

            return new PageResult(
                $path,
                $title,
                'matching',
                $baselineUrl,
                $candidateUrl,
                $baselineSnapshot->httpStatus,
                $candidateSnapshot->httpStatus,
                []
            );
        }

        $baselineArtifactPath = $artifactImageWriter->artifactPath($pageDir, 'baseline');
        $candidateArtifactPath = $artifactImageWriter->artifactPath($pageDir, 'candidate');

        $this->copyScreenshotToArtifact($baselineScreenshotPath, $baselineArtifactPath, $artifactImageWriter);
        $this->copyScreenshotToArtifact($candidateScreenshotPath, $candidateArtifactPath, $artifactImageWriter);
        $this->filesystem->removeFileIfExists($baselineScreenshotPath);
        $this->filesystem->removeFileIfExists($candidateScreenshotPath);

        return new PageResult(
            $path,
            $title,
            'matching',
            $baselineUrl,
            $candidateUrl,
            $baselineSnapshot->httpStatus,
            $candidateSnapshot->httpStatus,
            [
                'baseline' => $this->filesystem->relativePath($outputDir, $baselineArtifactPath),
                'candidate' => $this->filesystem->relativePath($outputDir, $candidateArtifactPath),
            ]
        );
    }

    /**
     * @param array<string, ?string> $artifacts
     * @return array<string, ?string>
     */
    private function relativeArtifacts(string $outputDir, array $artifacts): array
    {
        $normalized = [];

        foreach ($artifacts as $name => $path) {
            if (!is_string($path) || $path === '') {
                $normalized[$name] = null;
                continue;
            }

            $normalized[$name] = $this->filesystem->relativePath($outputDir, $path);
        }

        return $normalized;
    }

    private function buildSummary(
        RunConfiguration $configuration,
        string $generatedAt,
        string $outputDir,
        string $reportPath,
        string $reportJsonPath,
        array $pageResults
    ): RunSummary {
        $counts = [
            'matching' => 0,
            'different' => 0,
            'http_error' => 0,
            'runtime_error' => 0,
        ];

        foreach ($pageResults as $pageResult) {
            if ($pageResult instanceof PageResult && array_key_exists($pageResult->status, $counts)) {
                $counts[$pageResult->status]++;
            }
        }

        return new RunSummary(
            baselineBaseUrl: $configuration->baselineBaseUrl,
            candidateBaseUrl: $configuration->candidateBaseUrl,
            generatedAt: $generatedAt,
            outputDir: $outputDir,
            reportPath: $reportPath,
            reportJsonPath: $reportJsonPath,
            totalPages: count($pageResults),
            matchingPages: $counts['matching'],
            differentPages: $counts['different'],
            httpErrorPages: $counts['http_error'],
            runtimeErrorPages: $counts['runtime_error'],
            shiftTolerance: $configuration->shiftTolerance,
            captureMatchingScreenshots: $configuration->captureMatchingScreenshots
        );
    }

    private function resolveOutputDirectory(?string $configuredOutputDir): string
    {
        $configuredOutputDir = $configuredOutputDir === null ? '' : trim($configuredOutputDir);

        if ($configuredOutputDir !== '') {
            return $configuredOutputDir;
        }

        return $this->filesystem->joinPath('var', 'results', 'run-' . $this->clock->timestampForRunDirectory());
    }

    private function joinBaseUrlAndPath(string $baseUrl, string $path): string
    {
        return $path === '/'
            ? $baseUrl
            : rtrim($baseUrl, '/') . $path;
    }

    private function fallbackTitle(string $path, string $baselineUrl, string $candidateUrl): string
    {
        if ($path !== '') {
            return $path;
        }

        return $baselineUrl !== '' ? $baselineUrl : $candidateUrl;
    }

    private function bestTitle(string $baselineTitle, string $candidateTitle, string $path, string $baselineUrl, string $candidateUrl): string
    {
        $title = trim($baselineTitle);

        if ($title !== '') {
            return $title;
        }

        $title = trim($candidateTitle);

        if ($title !== '') {
            return $title;
        }

        return $this->fallbackTitle($path, $baselineUrl, $candidateUrl);
    }

    /**
     * Emit lightweight progress messages during normal runs.
     *
     * Long-running crawls should visibly advance by default. Symfony quiet mode
     * disables these lines.
     */
    private function logVerbose(RunConfiguration $configuration, string $message): void
    {
        if (!$configuration->verbose) {
            return;
        }

        $this->emitConsoleLine($configuration, $message);
    }

    private function logSnapshotDiagnostics(RunConfiguration $configuration, string $label, \LocalVisualDiff\Browser\PageSnapshot $snapshot): void
    {
        if (!$configuration->veryVerbose) {
            return;
        }

        $languages = $snapshot->navigatorLanguages === []
            ? '-'
            : implode(', ', $snapshot->navigatorLanguages);

        $this->logVerbose($configuration, sprintf('  %s requested: %s', $label, $snapshot->pageUrl));
        $this->logVerbose($configuration, sprintf('  %s final: %s', $label, $snapshot->finalUrl !== '' ? $snapshot->finalUrl : $snapshot->pageUrl));
        $this->logVerbose($configuration, sprintf('  %s status: %d', $label, $snapshot->httpStatus));
        $this->logVerbose($configuration, sprintf('  %s title: %s', $label, $snapshot->title !== '' ? $snapshot->title : '-'));
        $this->logVerbose($configuration, sprintf('  %s navigator.language: %s', $label, $snapshot->navigatorLanguage !== '' ? $snapshot->navigatorLanguage : '-'));
        $this->logVerbose($configuration, sprintf('  %s navigator.languages: %s', $label, $languages));
    }

    private function logPageResult(RunConfiguration $configuration, PageResult $pageResult): void
    {
        if (!$configuration->verbose) {
            return;
        }

        $statusLabel = $this->statusLabel($pageResult);

        if ($configuration->decoratedOutput) {
            $statusLabel = $this->colorizedStatusLabel($pageResult, $statusLabel);
        }

        $this->emitConsoleLine(
            $configuration,
            sprintf('Comparing %s ... %s', $pageResult->path, $statusLabel)
        );
    }

    private function statusLabel(PageResult $pageResult): string
    {
        return match ($pageResult->status) {
            'matching' => 'matching',
            'different' => 'different',
            'http_error' => $this->httpErrorLabel($pageResult),
            'runtime_error' => 'runtime error',
            default => $pageResult->status,
        };
    }

    private function httpErrorLabel(PageResult $pageResult): string
    {
        if ($pageResult->httpStatusBaseline >= 400) {
            return sprintf('HTTP error %d', $pageResult->httpStatusBaseline);
        }

        if ($pageResult->httpStatusCandidate >= 400) {
            return sprintf('HTTP error %d', $pageResult->httpStatusCandidate);
        }

        return 'HTTP error';
    }

    private function colorizedStatusLabel(PageResult $pageResult, string $statusLabel): string
    {
        return match ($pageResult->status) {
            'matching' => sprintf('<fg=green>%s</>', $statusLabel),
            'different' => sprintf('<fg=yellow>%s</>', $statusLabel),
            'http_error', 'runtime_error' => sprintf('<fg=red>%s</>', $statusLabel),
            default => $statusLabel,
        };
    }

    private function logProfile(RunConfiguration $configuration, RunProfiler $profiler): void
    {
        if (!$configuration->verbose) {
            return;
        }

        foreach ($profiler->formatLines() as $line) {
            $this->emitConsoleLine($configuration, $line);
        }
    }

    private function emitConsoleLine(RunConfiguration $configuration, string $message): void
    {
        if ($configuration->consoleWriter instanceof \Closure) {
            ($configuration->consoleWriter)($message);

            return;
        }

        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function copyScreenshotToArtifact(string $sourcePath, string $targetPath, ArtifactImageWriter $artifactImageWriter): void
    {
        $image = new \Imagick();
        $image->readImage($sourcePath);
        $artifactImageWriter->writeImage($image, $targetPath);
    }

    private function imageComparatorFor(string $artifactImageFormat): ImageComparator
    {
        if (strtolower(trim($artifactImageFormat)) === 'webp') {
            return $this->imageComparator;
        }

        return new ImageComparator(new ArtifactImageWriter($artifactImageFormat));
    }
}
