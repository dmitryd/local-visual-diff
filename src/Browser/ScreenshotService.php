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
        bool $scrollWarmup = false,
        string $acceptLanguage = 'de, en',
        ?string $configuredBrowserPath = null,
        ?string $osFamily = null
    ): PageSnapshot {
        $page = null;
        $closeBrowserAfterFailure = false;

        try {
            $browser = $this->acquireBrowser($configuredBrowserPath, $osFamily);
            $page = $browser->createPage();
            $this->applyLanguagePreferences($page, $acceptLanguage, $timeoutMs);
            $this->enableNetworkEvents($page, $timeoutMs);
            $this->watchMainDocumentContentType($page);

            $page->setViewport($viewportWidth, $viewportHeight)->await($timeoutMs);

            $navigation = $page->navigate($url);
            $navigation->waitForNavigation(Page::LOAD, $timeoutMs);

            $this->warmUpFullPageRendering($timeoutMs, $scrollWarmup, $page);
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
                $pageMetadata['navigatorLanguages'],
                $pageMetadata['hrefs'],
                $pageMetadata['baseHref']
            );
        } catch (NonHtmlDocumentException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $closeBrowserAfterFailure = true;

            throw new RuntimeException(
                sprintf('Could not capture screenshot for %s: %s', $url, $throwable->getMessage()),
                0,
                $throwable
            );
        } finally {
            if ($page instanceof Page) {
                $this->closePage($page);
            }

            if ($closeBrowserAfterFailure) {
                $this->closeBrowser();
            }
        }
    }

    /**
     * Render a baseline/candidate page pair with both navigations in flight.
     *
     * This is intentionally different from calling `capture()` twice. Both
     * browser tabs are prepared first, then both navigations are started before
     * we wait for either one. That lets Chromium load the two pages in parallel
     * and keeps the later screenshots closer together in time.
     *
     * @return array{baseline:PageSnapshot, candidate:PageSnapshot}
     */
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
        $baselinePage = null;
        $candidatePage = null;
        $closeBrowserAfterFailure = false;

        try {
            $browser = $this->acquireBrowser($configuredBrowserPath, $osFamily);
            $baselinePage = $browser->createPage();
            $candidatePage = $browser->createPage();

            $this->preparePageForCapture($baselinePage, $viewportWidth, $viewportHeight, $timeoutMs, $acceptLanguage);
            $this->preparePageForCapture($candidatePage, $viewportWidth, $viewportHeight, $timeoutMs, $acceptLanguage);
            $this->enableNetworkEvents($baselinePage, $timeoutMs);
            $this->enableNetworkEvents($candidatePage, $timeoutMs);
            $this->watchMainDocumentContentType($baselinePage, 'baseline');
            $this->watchMainDocumentContentType($candidatePage, 'candidate');

            $baselineNavigation = $baselinePage->navigate($baselineUrl);
            $candidateNavigation = $candidatePage->navigate($candidateUrl);

            $baselineNavigation->waitForNavigation(Page::LOAD, $timeoutMs);
            $candidateNavigation->waitForNavigation(Page::LOAD, $timeoutMs);

            $this->warmUpFullPageRendering($timeoutMs, $scrollWarmup, $baselinePage, $candidatePage);
            $this->pageStabilizer->injectInto($baselinePage, $timeoutMs);
            $this->pageStabilizer->injectInto($candidatePage, $timeoutMs);
            $this->waitMilliseconds($settleMs);

            $baselineMetadata = $this->readPageMetadata($baselinePage, $timeoutMs);
            $candidateMetadata = $this->readPageMetadata($candidatePage, $timeoutMs);

            $baselinePage->screenshot([
                'format' => 'png',
                'captureBeyondViewport' => true,
            ])->saveToFile($baselineImagePath, $timeoutMs);

            $candidatePage->screenshot([
                'format' => 'png',
                'captureBeyondViewport' => true,
            ])->saveToFile($candidateImagePath, $timeoutMs);

            return [
                'baseline' => new PageSnapshot(
                    $baselineUrl,
                    $baselineMetadata['finalUrl'],
                    $baselineMetadata['title'],
                    $baselineMetadata['httpStatus'],
                    $baselineImagePath,
                    $baselineMetadata['pageHeight'],
                    $baselineMetadata['navigatorLanguage'],
                    $baselineMetadata['navigatorLanguages'],
                    $baselineMetadata['hrefs'],
                    $baselineMetadata['baseHref']
                ),
                'candidate' => new PageSnapshot(
                    $candidateUrl,
                    $candidateMetadata['finalUrl'],
                    $candidateMetadata['title'],
                    $candidateMetadata['httpStatus'],
                    $candidateImagePath,
                    $candidateMetadata['pageHeight'],
                    $candidateMetadata['navigatorLanguage'],
                    $candidateMetadata['navigatorLanguages'],
                    $candidateMetadata['hrefs'],
                    $candidateMetadata['baseHref']
                ),
            ];
        } catch (NonHtmlDocumentException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $closeBrowserAfterFailure = true;

            throw new RuntimeException(
                sprintf(
                    'Could not capture screenshot pair for %s and %s: %s',
                    $baselineUrl,
                    $candidateUrl,
                    $throwable->getMessage()
                ),
                0,
                $throwable
            );
        } finally {
            if ($baselinePage instanceof Page) {
                $this->closePage($baselinePage);
            }

            if ($candidatePage instanceof Page) {
                $this->closePage($candidatePage);
            }

            if ($closeBrowserAfterFailure) {
                $this->closeBrowser();
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

    private function closePage(Page $page): void
    {
        try {
            $page->close();
        } catch (Throwable) {
            // A broken browser process can also make tab cleanup fail. The
            // caller will close the shared browser when the capture failed.
        }
    }

    /**
     * Trigger lazy-loaded content before full-page capture.
     *
     * Chromium's full-page screenshot can capture beyond the current viewport
     * without eagerly loading all images first. We therefore reveal common
     * lazy-image attributes and wait for fonts/images/layout to settle.
     *
     * Scroll-driven warm-up is intentionally optional. Some sites mutate sticky
     * navigation or active menu state while scrolling, and those mutations can
     * create false visual diffs even when a normal browser view is identical.
     * Evaluations are started for every page before waiting, which keeps
     * baseline/candidate warm-up concurrent in pair captures.
     */
    private function warmUpFullPageRendering(int $timeoutMs, bool $scrollWarmup, Page ...$pages): void
    {
        $evaluations = [];

        foreach ($pages as $page) {
            $evaluations[] = $page->evaluate($this->fullPageWarmUpScript($scrollWarmup));
        }

        foreach ($evaluations as $evaluation) {
            $evaluation->getReturnValue($timeoutMs);
        }
    }

    private function fullPageWarmUpScript(bool $scrollWarmup): string
    {
        $scrollWarmupJson = json_encode($scrollWarmup, JSON_THROW_ON_ERROR);

        return <<<JS
(async function () {
    const scrollWarmup = {$scrollWarmupJson};
    const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));
    const lazySourceAttributes = [
        'data-src',
        'data-original',
        'data-lazy-src',
        'data-echo',
        'data-url',
    ];

    const lazySourceSetAttributes = [
        'data-srcset',
        'data-lazy-srcset',
    ];

    const lazyBackgroundAttributes = [
        'data-background-image',
        'data-bg',
        'data-bg-src',
        'data-lazy-background',
    ];

    const pageHeight = () => Math.max(
        document.body ? document.body.scrollHeight : 0,
        document.documentElement ? document.documentElement.scrollHeight : 0
    );

    const triggerViewportObservers = () => {
        window.dispatchEvent(new Event('scroll'));
        window.dispatchEvent(new Event('resize'));
        document.dispatchEvent(new Event('scroll'));
    };

    const firstAttributeValue = (element, attributes) => {
        for (const attribute of attributes) {
            const value = element.getAttribute(attribute);

            if (value) {
                return value;
            }
        }

        return '';
    };

    const revealLazyMedia = () => {
        document.querySelectorAll('img').forEach((image) => {
            image.loading = 'eager';

            const source = firstAttributeValue(image, lazySourceAttributes);

            if (source && image.getAttribute('src') !== source) {
                image.setAttribute('src', source);
            }

            const sourceSet = firstAttributeValue(image, lazySourceSetAttributes);

            if (sourceSet && image.getAttribute('srcset') !== sourceSet) {
                image.setAttribute('srcset', sourceSet);
            }
        });

        document.querySelectorAll('source').forEach((sourceElement) => {
            const sourceSet = firstAttributeValue(sourceElement, lazySourceSetAttributes);

            if (sourceSet && sourceElement.getAttribute('srcset') !== sourceSet) {
                sourceElement.setAttribute('srcset', sourceSet);
            }
        });

        document.querySelectorAll('*').forEach((element) => {
            const background = firstAttributeValue(element, lazyBackgroundAttributes);

            if (background) {
                element.style.backgroundImage = 'url("' + background.replace(/"/g, '\\"') + '")';
            }
        });
    };

    const incompleteImageCount = () => Array
        .from(document.images)
        .filter((image) => !image.complete)
        .length;

    const waitForImages = async () => {
        const images = Array
            .from(document.images)
            .filter((image) => !image.complete);

        if (images.length === 0) {
            return;
        }

        await Promise.race([
            Promise.all(images.map((image) => new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            }))),
            wait(2000),
        ]);
    };

    const waitForFonts = async () => {
        if (!document.fonts || !document.fonts.ready) {
            return;
        }

        await Promise.race([
            document.fonts.ready,
            wait(2000),
        ]);
    };

    revealLazyMedia();
    triggerViewportObservers();
    await waitForFonts();
    await waitForImages();

    if (scrollWarmup) {
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 900;
        const step = Math.max(200, Math.floor(viewportHeight * 0.8));
        let previousHeight = 0;

        for (let pass = 0; pass < 3; pass++) {
            revealLazyMedia();

            const height = pageHeight();

            for (let y = 0; y < height; y += step) {
                window.scrollTo(0, y);
                triggerViewportObservers();
                revealLazyMedia();
                await wait(80);
            }

            window.scrollTo(0, height);
            triggerViewportObservers();
            revealLazyMedia();
            await waitForImages();
            await wait(150);

            const currentHeight = pageHeight();

            if (pass > 0 && Math.abs(currentHeight - previousHeight) < 2) {
                break;
            }

            previousHeight = currentHeight;
        }
    }

    window.scrollTo(0, 0);
    triggerViewportObservers();

    let stableChecks = 0;
    let lastHeight = pageHeight();
    let lastIncompleteImages = incompleteImageCount();

    for (let attempt = 0; attempt < 10; attempt++) {
        revealLazyMedia();
        await waitForImages();
        await wait(150);

        const currentHeight = pageHeight();
        const currentIncompleteImages = incompleteImageCount();

        if (currentHeight === lastHeight && currentIncompleteImages === lastIncompleteImages) {
            stableChecks++;
        } else {
            stableChecks = 0;
        }

        if (stableChecks >= 2) {
            break;
        }

        lastHeight = currentHeight;
        lastIncompleteImages = currentIncompleteImages;
    }

    return true;
})()
JS;
    }

    /**
     * Apply deterministic page-level settings before navigation starts.
     */
    private function preparePageForCapture(
        Page $page,
        int $viewportWidth,
        int $viewportHeight,
        int $timeoutMs,
        string $acceptLanguage
    ): void {
        $this->applyLanguagePreferences($page, $acceptLanguage, $timeoutMs);
        $page->setViewport($viewportWidth, $viewportHeight)->await($timeoutMs);
    }

    /**
     * Enable response events so non-HTML main documents can be skipped without
     * issuing a separate HTTP request before browser navigation.
     *
     * @throws CommunicationException
     */
    private function enableNetworkEvents(Page $page, int $timeoutMs): void
    {
        $response = $page->getSession()->sendMessageSync(
            new Message('Network.enable'),
            $timeoutMs
        );

        if (!$response->isSuccessful()) {
            throw new CommunicationException((string) $response->getErrorMessage());
        }
    }

    /**
     * Watch the main frame's document response and abort capture for non-HTML.
     */
    private function watchMainDocumentContentType(Page $page, string $side = ''): void
    {
        $mainFrameId = $page->getFrameManager()->getMainFrame()->getFrameId();

        $page->getSession()->on(
            'method:Network.responseReceived',
            static function (array $params) use ($mainFrameId, $side): void {
                if (($params['type'] ?? '') !== 'Document' || ($params['frameId'] ?? '') !== $mainFrameId) {
                    return;
                }

                $response = is_array($params['response'] ?? null) ? $params['response'] : [];
                $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
                $contentType = self::headerValue($headers, 'content-type');
                $mimeType = (string) ($response['mimeType'] ?? '');

                if (!HtmlDocumentContentType::isKnownNonHtml($contentType, $mimeType)) {
                    return;
                }

                throw new NonHtmlDocumentException(
                    (string) ($response['url'] ?? ''),
                    is_numeric($response['status'] ?? null) ? (int) $response['status'] : 0,
                    HtmlDocumentContentType::preferredType($contentType, $mimeType),
                    $side
                );
            }
        );
    }

    /**
     * Read a response header case-insensitively from CDP's header map.
     *
     * @param array<string, mixed> $headers
     */
    private static function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (strtolower((string) $headerName) !== strtolower($name)) {
                continue;
            }

            return trim((string) $value);
        }

        return '';
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
     *   navigatorLanguages:array<int,string>,
     *   hrefs:array<int,string>,
     *   baseHref:string
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
    const baseElement = document.querySelector('base[href]');

    return {
        finalUrl: window.location.href || '',
        title: document.title || '',
        httpStatus: entry && typeof entry.responseStatus === 'number' ? entry.responseStatus : 200,
        pageHeight: Math.max(bodyHeight, documentHeight),
        navigatorLanguage: navigator.language || '',
        navigatorLanguages: Array.isArray(navigator.languages) ? navigator.languages : [],
        hrefs: Array
            .from(document.querySelectorAll('a[href]'))
            .map((anchor) => anchor.getAttribute('href') || '')
            .filter((href) => href.trim() !== ''),
        baseHref: baseElement ? (baseElement.getAttribute('href') || '') : '',
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
                'hrefs' => [],
                'baseHref' => '',
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
            'hrefs' => array_values(array_filter(
                array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    is_array($metadata['hrefs'] ?? null) ? $metadata['hrefs'] : []
                ),
                static fn (string $value): bool => $value !== ''
            )),
            'baseHref' => trim((string) ($metadata['baseHref'] ?? '')),
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
