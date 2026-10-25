<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Integration;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use LocalVisualDiff\Browser\NonHtmlDocumentException;
use LocalVisualDiff\Browser\PageSnapshot;
use LocalVisualDiff\Browser\ScreenshotService;
use LocalVisualDiff\Command\CompareCommand;
use LocalVisualDiff\Run\ComparisonRunner;
use LocalVisualDiff\Support\Clock;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercise recursive crawl discovery through the real command so the crawler,
 * runner, and report all agree on which baseline pages were discovered.
 */
final class CrawlHomepageLinksCommandTest extends TestCase
{
    public function testSinglePageCommandProcessesOnlyExplicitTarget(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local/en/check-this/page' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/should-not-be-crawled">Should not be crawled</a>
  </body>
</html>
HTML,
                ],
                [
                    'http://legacy.local/en/check-this/page' => ['title' => 'Target page', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/en/check-this/page' => ['title' => 'Target page', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--single-page' => '/en/check-this/page/',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing /en/check-this/page ... matching', $tester->getDisplay());
            self::assertStringNotContainsString('Comparing / ...', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['totalPages']);
            self::assertSame(['/en/check-this/page'], array_column($report['results'], 'path'));
            self::assertSame(['matching'], array_column($report['results'], 'status'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testSinglePageCommandNormalizesExplicitQueryTarget(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local/search?a=1&b=2' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local/search?a=1&b=2' => ['title' => 'Search', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/search?a=1&b=2' => ['title' => 'Search', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--single-page' => '/search/?b=2&a=1',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing /search?a=1&b=2 ... matching', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['totalPages']);
            self::assertSame(['/search?a=1&b=2'], array_column($report['results'], 'path'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testCrawlEnabledCommandProcessesRecursiveDiscoveredLinks(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="about">About</a>
    <a href="https://twitter.com/example">Twitter</a>
  </body>
</html>
HTML,
                    'http://legacy.local/about' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/contact">Contact</a>
  </body>
</html>
HTML,
                    'http://legacy.local/contact' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/contact' => ['title' => 'Contact', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/contact' => ['title' => 'Contact', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--capture-matching-screenshots' => false,
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(3, $report['summary']['totalPages']);
            self::assertSame(3, $report['summary']['matchingPages']);
            self::assertSame(['/', '/about', '/contact'], array_column($report['results'], 'path'));
            self::assertSame(['matching', 'matching', 'matching'], array_column($report['results'], 'status'));
            self::assertSame(['Home', 'About', 'Contact'], array_column($report['results'], 'title'));

            $html = file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertIsString($html);
            self::assertStringContainsString('About', $html);
            self::assertStringContainsString('Contact', $html);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testIgnoredQueryParamPatternDeduplicatesRecursiveTargets(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/search/?tx_solr[q]=one&category=cards">Search one</a>
    <a href="/search/?tx_solr[q]=two&category=cards">Search two</a>
  </body>
</html>
HTML,
                    'http://legacy.local/search?category=cards' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/search?category=cards' => ['title' => 'Search', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/search?category=cards' => ['title' => 'Search', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--ignored-query-param-name-pattern' => '/^tx_solr\\[/',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(2, $report['summary']['totalPages']);
            self::assertSame(['/', '/search?category=cards'], array_column($report['results'], 'path'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testCrawlerDoesNotCaptureObviousDocumentLinks(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/fileadmin/Documents/form.docx">Download form</a>
    <a href="/about">About</a>
  </body>
</html>
HTML,
                    'http://legacy.local/about' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(2, $report['summary']['totalPages']);
            self::assertSame(0, $report['summary']['runtimeErrorPages']);
            self::assertSame(['/', '/about'], array_column($report['results'], 'path'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testSinglePageNonHtmlDocumentIsReportedAsSkipped(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [],
                [
                    'http://legacy.local/file.docx' => [
                        'httpStatus' => 200,
                        'nonHtmlContentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--single-page' => '/file.docx',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Skipping /file.docx ... non-HTML', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['totalPages']);
            self::assertSame(1, $report['summary']['skippedPages']);
            self::assertSame(0, $report['summary']['runtimeErrorPages']);
            self::assertSame('skipped', $report['results'][0]['status']);
            self::assertSame(
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $report['results'][0]['contentTypeBaseline']
            );
            self::assertSame('non-html document', $report['results'][0]['skipReason']);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testCandidateNonHtmlDocumentIsReportedAsSkipped(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [],
                [
                    'http://legacy.local/download' => ['title' => 'Download', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/download' => [
                        'httpStatus' => 200,
                        'nonHtmlContentType' => 'application/pdf',
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--single-page' => '/download',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('skipped', $report['results'][0]['status']);
            self::assertSame('', $report['results'][0]['contentTypeBaseline']);
            self::assertSame('application/pdf', $report['results'][0]['contentTypeCandidate']);
            self::assertSame(0, $report['summary']['runtimeErrorPages']);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testMaxPagesOneKeepsTheRunAtHomepageOnly(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/about">About</a>
    <a href="/contact">Contact</a>
  </body>
</html>
HTML,
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--max-pages' => 1,
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['totalPages']);
            self::assertSame(['/'], array_column($report['results'], 'path'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testBaselineHomepageHttpErrorMismatchStopsPageDiscovery(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="about">About</a>
    <a href="contact">Contact</a>
  </body>
</html>
HTML,
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 500, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Comparing / ... HTTP error 500/200', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['totalPages']);
            self::assertSame(1, $report['summary']['httpErrorPages']);
            self::assertSame(['http_error'], array_column($report['results'], 'status'));
            self::assertSame(['HTTP error 500'], array_column($report['results'], 'statusLabel'));
            self::assertSame(['500/200'], array_column($report['results'], 'httpStatusLabel'));

            $html = file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertIsString($html);
            self::assertStringContainsString('HTTP error 500', $html);
            self::assertStringContainsString('<span class="http-status">500/200</span>', $html);
            self::assertStringNotContainsString('About', $html);
            self::assertStringNotContainsString('Contact', $html);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testEqualHttpErrorStatusesStillCompareAndDiscoverLinks(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/login-help">Login help</a>
  </body>
</html>
HTML,
                    'http://legacy.local/login-help' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Forbidden', 'httpStatus' => 403, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Forbidden', 'httpStatus' => 403, 'pageHeight' => 600],
                    'http://legacy.local/login-help' => ['title' => 'Login help', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/login-help' => ['title' => 'Login help', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['/', '/login-help'], array_column($report['results'], 'path'));
            self::assertSame(['matching', 'matching'], array_column($report['results'], 'status'));
            self::assertSame(['403', ''], array_column($report['results'], 'httpStatusLabel'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testHttpErrorMismatchDiscoversLinksWhenBaselineIsSuccessful(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/about">About</a>
  </body>
</html>
HTML,
                    'http://legacy.local/about' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Broken', 'httpStatus' => 500, 'pageHeight' => 600],
                    'http://legacy.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['/', '/about'], array_column($report['results'], 'path'));
            self::assertSame(['http_error', 'matching'], array_column($report['results'], 'status'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testTransientServerErrorMismatchIsRetriedBeforeReporting(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => [
                        'captures' => [
                            ['title' => 'Temporary error', 'httpStatus' => 500, 'pageHeight' => 600],
                            ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                        ],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Retrying / after HTTP 200/500 ...', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['matching'], array_column($report['results'], 'status'));
            self::assertSame([200], array_column($report['results'], 'httpStatusCandidate'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testCaptureFailureIsRetriedBeforeReportingRuntimeError(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => [
                        'captures' => [
                            ['exception' => 'Browser session is closed'],
                            ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                        ],
                    ],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Retrying / after capture failure:', $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['matching'], array_column($report['results'], 'status'));
            self::assertSame(0, $report['summary']['runtimeErrorPages']);
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testMaxPagesLimitsComparedPagesDuringStreamedCrawl(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/about">About</a>
    <a href="/contact">Contact</a>
  </body>
</html>
HTML,
                    'http://legacy.local/about' => '<!doctype html><html><body><a href="/pricing">Pricing</a></body></html>',
                    'http://legacy.local/contact' => '<!doctype html><html><body></body></html>',
                    'http://legacy.local/pricing' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
                '--max-pages' => 2,
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(2, $report['summary']['totalPages']);
            self::assertSame(['/', '/about'], array_column($report['results'], 'path'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testCrawlUsesRedirectedDocumentUrlForRelativeLinks(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'html' => <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="about">About</a>
  </body>
</html>
HTML,
                        'finalUrl' => 'http://legacy.local/en/',
                    ],
                    'http://legacy.local/en/about' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600, 'finalUrl' => 'http://legacy.local/en/'],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/en/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/en/about' => ['title' => 'About', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['/', '/en/about'], array_column($report['results'], 'path'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    public function testCrawlUsesBaseHrefForRelativeLinks(): void
    {
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = $this->buildCommand(
                [
                    'http://legacy.local' => [
                        'html' => <<<'HTML'
<!doctype html>
<html>
  <head>
    <base href="/de/">
  </head>
  <body>
    <a href="contact">Contact</a>
  </body>
</html>
HTML,
                        'finalUrl' => 'http://legacy.local/en/',
                    ],
                    'http://legacy.local/de/contact' => '<!doctype html><html><body></body></html>',
                ],
                [
                    'http://legacy.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600, 'finalUrl' => 'http://legacy.local/en/'],
                    'http://upgraded.local' => ['title' => 'Home', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://legacy.local/de/contact' => ['title' => 'Contact', 'httpStatus' => 200, 'pageHeight' => 600],
                    'http://upgraded.local/de/contact' => ['title' => 'Contact', 'httpStatus' => 200, 'pageHeight' => 600],
                ]
            );

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'http://legacy.local',
                'candidate-base-url' => 'http://upgraded.local',
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['/', '/de/contact'], array_column($report['results'], 'path'));
        } finally {
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    /**
     * @param array<string, string|array{html:string, finalUrl?:string}> $htmlByUrl
     * @param array<string, array<string, mixed>> $snapshots
     */
    private function buildCommand(array $htmlByUrl, array $snapshots): CompareCommand
    {
        $snapshots = $this->withBaselineDomMetadata($htmlByUrl, $snapshots);

        return new CompareCommand(
            runner: new ComparisonRunner(
                screenshotService: new CrawlFixtureScreenshotService($snapshots)
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

    /**
     * @param array<string, string|array{html:string, finalUrl?:string}> $htmlByUrl
     * @param array<string, array<string, mixed>> $snapshots
     * @return array<string, array<string, mixed>>
     */
    private function withBaselineDomMetadata(array $htmlByUrl, array $snapshots): array
    {
        foreach ($htmlByUrl as $url => $document) {
            if (!isset($snapshots[$url])) {
                continue;
            }

            $html = is_array($document) ? $document['html'] : $document;
            $snapshots[$url]['hrefs'] = $this->extractHrefsFromHtml($html);
            $snapshots[$url]['baseHref'] = $this->extractBaseHrefFromHtml($html);

            if (is_array($document) && isset($document['finalUrl']) && !isset($snapshots[$url]['finalUrl'])) {
                $snapshots[$url]['finalUrl'] = $document['finalUrl'];
            }
        }

        return $snapshots;
    }

    /**
     * @return array<int, string>
     */
    private function extractHrefsFromHtml(string $html): array
    {
        $xpath = $this->createXPath($html);
        $nodes = $xpath->query('//a[@href]');

        if ($nodes === false) {
            return [];
        }

        $hrefs = [];

        foreach ($nodes as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);

            if ($href === '') {
                continue;
            }

            $hrefs[] = $href;
        }

        return $hrefs;
    }

    private function extractBaseHrefFromHtml(string $html): string
    {
        $xpath = $this->createXPath($html);
        $nodes = $xpath->query('(//base[@href])[1]');

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) $nodes->item(0)?->attributes?->getNamedItem('href')?->nodeValue);
    }

    private function createXPath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
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

final class CrawlFixtureScreenshotService extends ScreenshotService
{
    /**
     * @var array<string, int>
     */
    private array $captureCounts = [];

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
        ?string $osFamily = null,
        string $maskSelector = ''
    ): PageSnapshot {
        unset(
            $viewportWidth,
            $viewportHeight,
            $timeoutMs,
            $settleMs,
            $scrollWarmup,
            $acceptLanguage,
            $configuredBrowserPath,
            $osFamily,
            $maskSelector
        );

        $spec = $this->snapshotSpec($url);

        if (!is_array($spec)) {
            throw new RuntimeException(sprintf('No fixture screenshot configured for %s', $url));
        }

        if (is_string($spec['exception'] ?? null)) {
            throw new RuntimeException($spec['exception']);
        }

        if (is_string($spec['nonHtmlContentType'] ?? null)) {
            throw new NonHtmlDocumentException(
                $url,
                (int) ($spec['httpStatus'] ?? 200),
                $spec['nonHtmlContentType']
            );
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
            is_array($spec['navigatorLanguages'] ?? null) ? array_values($spec['navigatorLanguages']) : [],
            is_array($spec['hrefs'] ?? null) ? array_values($spec['hrefs']) : [],
            (string) ($spec['baseHref'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshotSpec(string $url): ?array
    {
        $spec = $this->snapshots[$url] ?? null;

        if (!is_array($spec)) {
            return null;
        }

        if (!is_array($spec['captures'] ?? null)) {
            return $spec;
        }

        $captureIndex = $this->captureCounts[$url] ?? 0;
        $this->captureCounts[$url] = $captureIndex + 1;
        $captures = array_values($spec['captures']);
        $selected = $captures[min($captureIndex, count($captures) - 1)] ?? null;

        return is_array($selected) ? $selected : null;
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
        ?string $osFamily = null,
        string $maskSelector = ''
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
                $osFamily,
                $maskSelector
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
                $osFamily,
                $maskSelector
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
