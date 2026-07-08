<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Integration;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use LocalVisualDiff\Browser\ScreenshotService;
use LocalVisualDiff\Browser\PageSnapshot;
use LocalVisualDiff\Command\CompareCommand;
use LocalVisualDiff\Compare\ComparisonResult;
use LocalVisualDiff\Compare\ImageComparisonFailedException;
use LocalVisualDiff\Compare\ImageComparatorInterface;
use LocalVisualDiff\Run\ComparisonRunner;
use LocalVisualDiff\Support\Clock;
use LocalVisualDiff\Support\Slugger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercise the real command and runner wiring with a deterministic screenshot
 * service so we can validate output directory creation and artifact handling
 * without depending on a live browser during this stage of the build.
 */
final class CompareHomepageCommandTest extends TestCase
{
    private readonly Slugger $slugger;

    protected function setUp(): void
    {
        $this->slugger = new Slugger();
    }

    public function testItCreatesATimestampedReportDirectoryForHomepageComparison(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local/',
                'candidate-base-url' => 'http://upgraded.local/',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'report.json');

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['totalPages']);
            self::assertSame(1, $report['summary']['matchingPages']);
            self::assertSame(['matching'], array_map(static fn (array $row): string => $row['status'], $report['results']));
            self::assertDirectoryDoesNotExist($outputDir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . $this->slugger->slugPath('/'));

            $html = file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertIsString($html);
            self::assertStringContainsString('Local Visual Diff Report', $html);
            self::assertStringContainsString('matching', $html);
            self::assertStringContainsString('Baseline', $html);
            self::assertStringContainsString('Candidate', $html);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testItWritesAContextArtifactForTallHomepageDifferences(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 1200,
                        'background' => 'white',
                        'rectangle' => [24, 520, 228, 640, 'black'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 1200,
                        'background' => 'white',
                        'rectangle' => [28, 520, 232, 640, 'black'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local/',
                'candidate-base-url' => 'http://upgraded.local/',
                '--max-fullpage-height' => 200,
                '--context-padding' => 40,
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['differentPages']);
            self::assertSame('different', $report['results'][0]['status']);
            self::assertNotEmpty($report['results'][0]['contextUrl']);

            $contextPath = $outputDir . DIRECTORY_SEPARATOR . $report['results'][0]['contextUrl'];
            self::assertFileExists($contextPath);

            $html = file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertIsString($html);
            self::assertStringContainsString('Context', $html);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testDefaultOutputShowsProgressAndReportPath(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... matching', $tester->getDisplay());
            self::assertStringContainsString('Report written to', $tester->getDisplay());
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testComparisonFailureBecomesRuntimeErrorAndReportIsWritten(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                    ],
                ],
                new FailingImageComparator()
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('image comparison failed in worker: memory exhausted', $tester->getDisplay());
            self::assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'report.json');

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['runtimeErrorPages']);
            self::assertSame('runtime_error', $report['results'][0]['status']);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testBaseUrlArgumentsWithPathsAreTrimmedToOriginsWithWarning(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local/de/',
                'candidate-base-url' => 'http://upgraded.local/de/',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Warning: baseline URL contains path/query/fragment "/de/"; using base URL http://legacy.local', $tester->getDisplay());
            self::assertStringContainsString('Warning: candidate URL contains path/query/fragment "/de/"; using base URL http://upgraded.local', $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... matching', $tester->getDisplay());
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testSinglePageRejectsInvalidTargets(): void
    {
        $invalidTargets = [
            '' => 'The --single-page option requires a non-empty path or query target.',
            'http://legacy.local/page' => 'The --single-page option accepts only path/query targets, not URLs:',
            '//legacy.local/page' => 'The --single-page option accepts only path/query targets, not URLs:',
            '/page#section' => 'The --single-page option does not support URL fragments:',
        ];

        foreach ($invalidTargets as $target => $expectedMessage) {
            $tester = new CommandTester($this->buildCommand([]));
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--single-page' => $target,
            ]);

            self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString($expectedMessage, $tester->getDisplay());
        }
    }

    public function testDefaultOutputShowsDifferentResultWhenHomepageDiffers(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'red'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... different', $tester->getDisplay());
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testSameHttpErrorStatusStillComparesMatchingHomepageScreenshots(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Login required',
                        'httpStatus' => 403,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Login required',
                        'httpStatus' => 403,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... matching (HTTP 403)', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['matchingPages']);
            self::assertSame(0, $report['summary']['httpErrorPages']);
            self::assertSame('matching', $report['results'][0]['status']);
            self::assertSame('403', $report['results'][0]['httpStatusLabel']);

            $html = file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertIsString($html);
            self::assertStringContainsString('<th>HTTP</th>', $html);
            self::assertStringContainsString('<span class="http-status">403</span>', $html);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testSameHttpErrorStatusStillComparesDifferentHomepageScreenshots(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Login required',
                        'httpStatus' => 403,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Login required',
                        'httpStatus' => 403,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'red'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... different (HTTP 403)', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['differentPages']);
            self::assertSame(0, $report['summary']['httpErrorPages']);
            self::assertSame('different', $report['results'][0]['status']);
            self::assertSame('403', $report['results'][0]['httpStatusLabel']);
            self::assertNotEmpty($report['results'][0]['diffUrl']);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testDifferentHttpErrorStatusesAreReportedAsHttpError(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Login required',
                        'httpStatus' => 403,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Not found',
                        'httpStatus' => 404,
                        'pageHeight' => 600,
                        'background' => 'white',
                        'rectangle' => [24, 24, 228, 160, 'black'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... HTTP error 403/404', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['httpErrorPages']);
            self::assertSame('http_error', $report['results'][0]['status']);
            self::assertSame('403/404', $report['results'][0]['httpStatusLabel']);
            self::assertNull($report['results'][0]['diffUrl']);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testQuietOutputSuppressesProgressAndReportPath(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ], ['verbosity' => OutputInterface::VERBOSITY_QUIET]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertSame('', $tester->getDisplay());
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testVerboseOutputShowsSnapshotDiagnostics(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'finalUrl' => 'http://legacy.local/de/',
                        'navigatorLanguage' => 'de',
                        'navigatorLanguages' => ['de', 'en'],
                    ],
                    'http://upgraded.local' => [
                        'title' => 'Home',
                        'httpStatus' => 200,
                        'pageHeight' => 600,
                        'finalUrl' => 'http://upgraded.local/de/',
                        'navigatorLanguage' => 'de',
                        'navigatorLanguages' => ['de', 'en'],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... matching', $tester->getDisplay());
            self::assertStringContainsString('Baseline requested: http://legacy.local', $tester->getDisplay());
            self::assertStringContainsString('Baseline final: http://legacy.local/de/', $tester->getDisplay());
            self::assertStringContainsString('Baseline navigator.language: de', $tester->getDisplay());
            self::assertStringContainsString('Report written to', $tester->getDisplay());
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    /**
     * Build a command instance with a deterministic screenshot service.
     *
     * @param array<string, array<string, mixed>> $snapshots
     */
    private function buildCommand(array $snapshots, ?ImageComparatorInterface $imageComparator = null): CompareCommand
    {
        return new CompareCommand(
            runner: new ComparisonRunner(
                screenshotService: new HomepageFixtureScreenshotService($snapshots),
                imageComparator: $imageComparator
            ),
            clock: new class () extends Clock {
                public function timestampForRunDirectory(): string
                {
                    return '20260331-103000';
                }

                public function formattedNow(): string
                {
                    return '2026-03-31 10:30:00';
                }
            }
        );
    }

    private function removeDirectoryIfExists(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

/**
 * Test double that simulates an isolated image worker failure.
 */
final class FailingImageComparator implements ImageComparatorInterface
{
    public function compare(
        string $baselinePath,
        string $candidatePath,
        int $shiftTolerance = 0,
        int $colorThreshold = 0,
        ?string $artifactDirectory = null,
        int $pageHeight = 0,
        int $maxFullpageHeight = 0,
        int $contextPadding = 200
    ): ComparisonResult {
        throw new ImageComparisonFailedException('image comparison failed in worker: memory exhausted');
    }
}

/**
 * Test double that renders deterministic PNGs instead of starting a browser.
 */
final class HomepageFixtureScreenshotService extends ScreenshotService
{
    /**
     * @param array<string, array<string, mixed>> $snapshots
     */
    public function __construct(private readonly array $snapshots)
    {
    }

    #[\Override]
    public function capture(
        string $url,
        string $imagePath,
        int $viewportWidth,
        int $viewportHeight,
        int $timeoutMs,
        int $settleMs,
        bool $scrollWarmup = false,
        string $acceptLanguage = 'de, en',
        ?string $configuredBrowserPath = null,
        ?string $osFamily = null
    ): PageSnapshot {
        unset(
            $viewportWidth,
            $viewportHeight,
            $timeoutMs,
            $settleMs,
            $scrollWarmup,
            $acceptLanguage,
            $configuredBrowserPath,
            $osFamily
        );

        $spec = $this->snapshots[$url] ?? null;

        if (!is_array($spec)) {
            throw new RuntimeException(sprintf('No fixture screenshot configured for %s', $url));
        }

        $this->writeImage($imagePath, $spec);

        return new PageSnapshot(
            $url,
            (string) ($spec['finalUrl'] ?? $url),
            (string) ($spec['title'] ?? ''),
            (int) ($spec['httpStatus'] ?? 200),
            $imagePath,
            (int) ($spec['pageHeight'] ?? 600),
            (string) ($spec['navigatorLanguage'] ?? ''),
            is_array($spec['navigatorLanguages'] ?? null) ? array_values($spec['navigatorLanguages']) : []
        );
    }

    /**
     * @return array{baseline:PageSnapshot, candidate:PageSnapshot}
     */
    #[\Override]
    public function capturePair(
        string $baselineUrl,
        string $baselineImagePath,
        string $candidateUrl,
        string $candidateImagePath,
        int $viewportWidth,
        int $viewportHeight,
        int $timeoutMs,
        int $settleMs,
        bool $scrollWarmup = false,
        string $acceptLanguage = 'de, en',
        ?string $configuredBrowserPath = null,
        ?string $osFamily = null
    ): array {
        return [
            'baseline' => $this->capture(
                $baselineUrl,
                $baselineImagePath,
                $viewportWidth,
                $viewportHeight,
                $timeoutMs,
                $settleMs,
                $scrollWarmup,
                $acceptLanguage,
                $configuredBrowserPath,
                $osFamily
            ),
            'candidate' => $this->capture(
                $candidateUrl,
                $candidateImagePath,
                $viewportWidth,
                $viewportHeight,
                $timeoutMs,
                $settleMs,
                $scrollWarmup,
                $acceptLanguage,
                $configuredBrowserPath,
                $osFamily
            ),
        ];
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function writeImage(string $path, array $spec): void
    {
        $width = (int) ($spec['width'] ?? 420);
        $height = (int) ($spec['pageHeight'] ?? 600);
        $background = (string) ($spec['background'] ?? 'white');
        $rectangle = is_array($spec['rectangle'] ?? null) ? $spec['rectangle'] : null;

        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel($background), 'png');
        $image->setImageFormat('png');

        if (is_array($rectangle) && count($rectangle) >= 5) {
            [$x1, $y1, $x2, $y2, $color] = $rectangle;

            $draw = new ImagickDraw();
            $draw->setFillColor(new ImagickPixel((string) $color));
            $draw->setStrokeColor(new ImagickPixel((string) $color));
            $draw->setStrokeWidth(1);
            $draw->setStrokeAntialias(false);
            $draw->rectangle((int) $x1, (int) $y1, (int) $x2, (int) $y2);

            $image->drawImage($draw);
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create screenshot directory: %s', $directory));
        }

        $image->writeImage($path);
    }
}
