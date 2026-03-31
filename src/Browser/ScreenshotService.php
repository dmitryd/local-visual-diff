<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

use HeadlessChromium\Browser as ChromeBrowser;
use HeadlessChromium\Exception\CommunicationException;
use HeadlessChromium\Exception\NoResponseAvailable;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;
use RuntimeException;
use Throwable;

/**
 * Drive a browser to render a page and write a PNG screenshot to disk.
 *
 * The service intentionally keeps browser startup, page stabilization, and
 * screenshot capture together so later orchestration code can treat a page
 * capture as a single operation.
 */
class ScreenshotService
{
    private ?ChromeBrowser $sharedBrowser = null;

    private ?string $sharedBrowserPath = null;

    private ?string $sharedOsFamily = null;

    public function __construct(
        private readonly BrowserFactory $browserFactory = new BrowserFactory(),
        private readonly PageStabilizer $pageStabilizer = new PageStabilizer()
    ) {
    }

    public function __destruct()
    {
        $this->closeBrowser();
    }

    /**
     * Render a page and capture a screenshot.
     *
     * @return PageSnapshot snapshot metadata for the rendered page
     */
    public function capture(
        string $url,
        string $imagePath,
        int $viewportWidth,
        int $viewportHeight,
        int $timeoutMs,
        int $settleMs,
        ?string $configuredBrowserPath = null,
        ?string $osFamily = null
    ): PageSnapshot {
        $page = null;

        try {
            $browser = $this->acquireBrowser($configuredBrowserPath, $osFamily);
            $page = $browser->createPage();

            $page->setViewport($viewportWidth, $viewportHeight)->await($timeoutMs);

            $navigation = $page->navigate($url);
            $navigation->waitForNavigation(Page::LOAD, $timeoutMs);

            $this->pageStabilizer->injectInto($page, $timeoutMs);
            $this->waitMilliseconds($settleMs);

            $pageMetadata = $this->readPageMetadata($page, $timeoutMs);

            $page->screenshot([
                'format' => 'png',
                'captureBeyondViewport' => true,
            ])->saveToFile($imagePath, $timeoutMs);

            return new PageSnapshot(
                $url,
                $pageMetadata['finalUrl'],
                $pageMetadata['title'],
                $pageMetadata['httpStatus'],
                $imagePath,
                $pageMetadata['pageHeight'],
                $pageMetadata['navigatorLanguage'],
                $pageMetadata['navigatorLanguages']
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                sprintf('Could not capture screenshot for %s: %s', $url, $throwable->getMessage()),
                0,
                $throwable
            );
        } finally {
            if ($page instanceof Page) {
                $page->close();
            }
        }
    }

    /**
     * Reuse one browser process for the whole run.
     *
     * Browser startup was the dominant capture cost during profiling. Reusing a
     * single process keeps captures isolated at the page-tab level while
     * avoiding repeated cold starts for every baseline/candidate pair.
     */
    private function acquireBrowser(?string $configuredBrowserPath, ?string $osFamily): ChromeBrowser
    {
        if (
            $this->sharedBrowser instanceof ChromeBrowser
            && $this->sharedBrowserPath === $configuredBrowserPath
            && $this->sharedOsFamily === $osFamily
        ) {
            return $this->sharedBrowser;
        }

        $this->closeBrowser();
        $this->sharedBrowser = $this->browserFactory->createBrowser($configuredBrowserPath, $osFamily);
        $this->sharedBrowserPath = $configuredBrowserPath;
        $this->sharedOsFamily = $osFamily;

        return $this->sharedBrowser;
    }

    private function closeBrowser(): void
    {
        if (!$this->sharedBrowser instanceof ChromeBrowser) {
            return;
        }

        $this->sharedBrowser->close();
        $this->sharedBrowser = null;
        $this->sharedBrowserPath = null;
        $this->sharedOsFamily = null;
    }

    /**
     * Read the screenshot metadata in one browser round-trip.
     *
     * Several small `evaluate` calls add noticeable latency. Pulling the
     * metadata we need in one script reduces protocol chatter while keeping the
     * returned data explicit and easy to inspect.
     *
     * @return array{
     *   finalUrl:string,
     *   title:string,
     *   httpStatus:int,
     *   pageHeight:int,
     *   navigatorLanguage:string,
     *   navigatorLanguages:array<int,string>
     * }
     *
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    private function readPageMetadata(Page $page, int $timeoutMs): array
    {
        $evaluation = $page->evaluate(<<<'JS'
(function () {
    const entries = performance.getEntriesByType('navigation');
    const entry = entries.length > 0 ? entries[entries.length - 1] : null;
    const bodyHeight = document.body ? document.body.scrollHeight : 0;
    const documentHeight = document.documentElement ? document.documentElement.scrollHeight : 0;

    return {
        finalUrl: window.location.href || '',
        title: document.title || '',
        httpStatus: entry && typeof entry.responseStatus === 'number' ? entry.responseStatus : 200,
        pageHeight: Math.max(bodyHeight, documentHeight),
        navigatorLanguage: navigator.language || '',
        navigatorLanguages: Array.isArray(navigator.languages) ? navigator.languages : [],
    };
})()
JS);

        $metadata = $evaluation->getReturnValue($timeoutMs);

        if (!is_array($metadata)) {
            return [
                'finalUrl' => '',
                'title' => '',
                'httpStatus' => 200,
                'pageHeight' => 0,
                'navigatorLanguage' => '',
                'navigatorLanguages' => [],
            ];
        }

        return [
            'finalUrl' => trim((string) ($metadata['finalUrl'] ?? '')),
            'title' => trim((string) ($metadata['title'] ?? '')),
            'httpStatus' => is_numeric($metadata['httpStatus'] ?? null) ? (int) $metadata['httpStatus'] : 200,
            'pageHeight' => is_numeric($metadata['pageHeight'] ?? null) ? (int) $metadata['pageHeight'] : 0,
            'navigatorLanguage' => trim((string) ($metadata['navigatorLanguage'] ?? '')),
            'navigatorLanguages' => array_values(array_map(
                static fn (mixed $value): string => (string) $value,
                is_array($metadata['navigatorLanguages'] ?? null) ? $metadata['navigatorLanguages'] : []
            )),
        ];
    }

    private function waitMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
