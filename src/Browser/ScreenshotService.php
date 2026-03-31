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
final class ScreenshotService
{
    public function __construct(
        private readonly BrowserFactory $browserFactory = new BrowserFactory(),
        private readonly PageStabilizer $pageStabilizer = new PageStabilizer()
    ) {
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
        $browser = null;

        try {
            $browser = $this->browserFactory->createBrowser($configuredBrowserPath, $osFamily);
            $page = $browser->createPage();

            $page->setViewport($viewportWidth, $viewportHeight)->await($timeoutMs);

            $navigation = $page->navigate($url);
            $navigation->waitForNavigation(Page::LOAD, $timeoutMs);

            $this->pageStabilizer->injectInto($page, $timeoutMs);
            $this->waitMilliseconds($settleMs);

            $title = $this->readPageTitle($page, $timeoutMs);
            $httpStatus = $this->readHttpStatus($page, $timeoutMs);
            $pageHeight = $this->readPageHeight($page, $timeoutMs);

            $page->screenshot([
                'format' => 'png',
                'captureBeyondViewport' => true,
            ])->saveToFile($imagePath, $timeoutMs);

            return new PageSnapshot($url, $title, $httpStatus, $imagePath, $pageHeight);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                sprintf('Could not capture screenshot for %s: %s', $url, $throwable->getMessage()),
                0,
                $throwable
            );
        } finally {
            if ($browser instanceof ChromeBrowser) {
                $browser->close();
            }
        }
    }

    /**
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    private function readPageTitle(Page $page, int $timeoutMs): string
    {
        $evaluation = $page->evaluate('document.title || ""');
        $title = $evaluation->getReturnValue($timeoutMs);

        return trim((string) $title);
    }

    /**
     * Try to read the HTTP status code from the navigation timing entry.
     *
     * If Chrome does not expose the status in the current build, fall back to
     * 200 so the caller still gets a usable snapshot object.
     *
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    private function readHttpStatus(Page $page, int $timeoutMs): int
    {
        $evaluation = $page->evaluate(<<<'JS'
(function () {
    const entries = performance.getEntriesByType('navigation');
    const entry = entries.length > 0 ? entries[entries.length - 1] : null;

    if (entry && typeof entry.responseStatus === 'number') {
        return entry.responseStatus;
    }

    return 200;
})()
JS);

        $status = $evaluation->getReturnValue($timeoutMs);

        return is_numeric($status) ? (int) $status : 200;
    }

    /**
     * Measure the rendered page height so later orchestration can reason about
     * when a page is too tall for a comfortable single-image review artifact.
     *
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    private function readPageHeight(Page $page, int $timeoutMs): int
    {
        $evaluation = $page->evaluate(<<<'JS'
(function () {
    const bodyHeight = document.body ? document.body.scrollHeight : 0;
    const documentHeight = document.documentElement ? document.documentElement.scrollHeight : 0;
    return Math.max(bodyHeight, documentHeight);
})()
JS);

        $height = $evaluation->getReturnValue($timeoutMs);

        return is_numeric($height) ? (int) $height : 0;
    }

    private function waitMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
