<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

use HeadlessChromium\Browser as ChromeBrowser;
use HeadlessChromium\BrowserFactory as ChromeBrowserFactory;

/**
 * Create configured browser instances for screenshot capture.
 *
 * This class keeps browser process setup in one place so the rest of the code
 * can treat browser automation as a black box.
 */
final class BrowserFactory
{
    public function __construct(
        private readonly BrowserLocator $browserLocator = new BrowserLocator()
    ) {
    }

    /**
     * Start a browser process using the resolved binary path.
     *
     * @param array<string, mixed> $options
     */
    public function createBrowser(?string $configuredBrowserPath = null, ?string $osFamily = null, array $options = []): ChromeBrowser
    {
        $browserBinary = $this->browserLocator->resolve($configuredBrowserPath, $osFamily);
        $factory = new ChromeBrowserFactory($browserBinary);

        $browser = $factory->createBrowser(array_replace([
            'headless' => true,
            'keepAlive' => false,
            'disableNotifications' => true,
        ], $options));

        return $browser;
    }
}
