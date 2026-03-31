<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

use HeadlessChromium\Browser as ChromeBrowser;
use HeadlessChromium\Communication\Message;
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
        string $acceptLanguage = 'de, en',
        ?string $configuredBrowserPath = null,
        ?string $osFamily = null
    ): PageSnapshot {
        $page = null;

        try {
            $browser = $this->acquireBrowser($configuredBrowserPath, $osFamily);
            $page = $browser->createPage();
            $this->applyLanguagePreferences($page, $acceptLanguage, $timeoutMs);

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

    /**
     * Configure request headers and browser-exposed locale values before the
     * first navigation so server-side and client-side language detection use
     * deterministic inputs.
     *
     * @throws CommunicationException
     */
    private function applyLanguagePreferences(Page $page, string $acceptLanguage, int $timeoutMs): void
    {
        $preferences = $this->normalizeLanguagePreferences($acceptLanguage);

        if ($preferences['header'] === '') {
            return;
        }

        $page->setExtraHTTPHeaders([
            'Accept-Language' => $preferences['header'],
        ]);

        $localeResponse = $page->getSession()->sendMessageSync(
            new Message('Emulation.setLocaleOverride', ['locale' => $preferences['primary']]),
            $timeoutMs
        );

        if (!$localeResponse->isSuccessful()) {
            throw new CommunicationException((string) $localeResponse->getErrorMessage());
        }

        $page->addPreScript($this->languageOverridePreScript(
            $preferences['primary'],
            $preferences['languages']
        ));
    }

    /**
     * Convert the configured Accept-Language string into the three values we
     * need during capture: the actual request header, a primary locale for the
     * emulation layer, and a simple language list for navigator overrides.
     *
     * @return array{header:string, primary:string, languages:array<int,string>}
     */
    private function normalizeLanguagePreferences(string $acceptLanguage): array
    {
        $header = trim($acceptLanguage);

        if ($header === '') {
            return [
                'header' => '',
                'primary' => '',
                'languages' => [],
            ];
        }

        $parts = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', $header)
        ), static fn (string $part): bool => $part !== ''));

        $languages = [];

        foreach ($parts as $part) {
            $language = trim(explode(';', $part, 2)[0]);

            if ($language === '') {
                continue;
            }

            $languages[] = str_replace('_', '-', $language);
        }

        if ($languages === []) {
            return [
                'header' => '',
                'primary' => '',
                'languages' => [],
            ];
        }

        return [
            'header' => implode(',', $parts),
            'primary' => $languages[0],
            'languages' => $languages,
        ];
    }

    /**
     * Override browser-exposed language properties on every new document.
     *
     * TYPO3 language redirects can depend on `navigator.language` or
     * `navigator.languages` in client-side logic after the initial response.
     * We therefore override those values as early as possible as part of page
     * setup, in addition to sending the matching request header.
     *
     * @param array<int, string> $languages
     */
    private function languageOverridePreScript(string $primaryLanguage, array $languages): string
    {
        $primaryLanguageJson = json_encode($primaryLanguage, JSON_THROW_ON_ERROR);
        $languagesJson = json_encode(array_values($languages), JSON_THROW_ON_ERROR);

        return <<<JS
(function () {
    const primaryLanguage = {$primaryLanguageJson};
    const languages = {$languagesJson};

    try {
        Object.defineProperty(navigator, 'language', {
            configurable: true,
            get: () => primaryLanguage,
        });

        Object.defineProperty(navigator, 'languages', {
            configurable: true,
            get: () => languages,
        });
    } catch (error) {
        // Some browser builds make these properties non-configurable. When that
        // happens we still keep the request header and locale override in place.
    }
})();
JS;
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
