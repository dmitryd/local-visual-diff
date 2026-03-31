<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Integration;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use LocalVisualDiff\Browser\PageSnapshot;
use LocalVisualDiff\Browser\ScreenshotService;
use LocalVisualDiff\Command\CompareCommand;
use LocalVisualDiff\Crawl\LinkExtractor;
use LocalVisualDiff\Crawl\PageQueueFactory;
use LocalVisualDiff\Crawl\RecursivePageCrawler;
use LocalVisualDiff\Crawl\UrlMapper;
use LocalVisualDiff\Crawl\UrlNormalizer;
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

    public function testBaselineHomepageHttpErrorStopsPageDiscovery(): void
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

            $report = json_decode((string) file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $report['summary']['totalPages']);
            self::assertSame(1, $report['summary']['httpErrorPages']);
            self::assertSame(['http_error'], array_column($report['results'], 'status'));
            self::assertSame(['HTTP error 500'], array_column($report['results'], 'statusLabel'));

            $html = file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertIsString($html);
            self::assertStringContainsString('HTTP error 500', $html);
            self::assertStringNotContainsString('About', $html);
            self::assertStringNotContainsString('Contact', $html);
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
     * @param array<string, string>              $htmlByUrl
     * @param array<string, array<string, mixed>> $snapshots
     */
    private function buildCommand(array $htmlByUrl, array $snapshots): CompareCommand
    {
        $pageQueueFactory = new PageQueueFactory(
            new RecursivePageCrawler(
                new FixtureLinkExtractor($htmlByUrl),
                new UrlMapper(),
                new UrlNormalizer()
            ),
            new UrlNormalizer()
        );

        return new CompareCommand(
            runner: new ComparisonRunner(
                screenshotService: new CrawlFixtureScreenshotService($snapshots),
                pageQueueFactory: $pageQueueFactory
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

final class FixtureLinkExtractor extends LinkExtractor
{
    /**
     * @param array<string, string|array{html:string, finalUrl?:string}> $documentsByUrl
     */
    public function __construct(private readonly array $documentsByUrl)
    {
    }

    protected function fetchDocument(string $pageUrl, bool $verifyTls = true): array
    {
        unset($verifyTls);

        if (!array_key_exists($pageUrl, $this->documentsByUrl)) {
            throw new RuntimeException(sprintf('No fixture HTML configured for %s', $pageUrl));
        }

        $document = $this->documentsByUrl[$pageUrl];

        if (is_array($document)) {
            return [
                'html' => $document['html'],
                'finalUrl' => $document['finalUrl'] ?? $pageUrl,
            ];
        }

        return [
            'html' => $document,
            'finalUrl' => $pageUrl,
        ];
    }
}

final class CrawlFixtureScreenshotService extends ScreenshotService
{
    /**
     * @param array<string, array<string, mixed>> $snapshots
     */
    public function __construct(private readonly array $snapshots)
    {
    }

    public function capture(
        string $url,
        string $imagePath,
        int $viewportWidth,
        int $viewportHeight,
        int $timeoutMs,
        int $settleMs,
        string $acceptLanguage = 'de, en',
        ?string $configuredBrowserPath = null,
        ?string $osFamily = null
    ): PageSnapshot {
        unset($viewportWidth, $viewportHeight, $timeoutMs, $settleMs, $acceptLanguage, $configuredBrowserPath, $osFamily);

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
