<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

use HeadlessChromium\Page;

/**
 * Inject a small CSS reset that disables motion and scroll animation.
 *
 * This reduces false positives caused by timing differences between pages.
 */
final class PageStabilizer
{
    private const STYLE_ID = 'local-visual-diff-motion-reset';

    /**
     * Build the JavaScript snippet that injects a style tag into the page.
     *
     * The snippet is written as JavaScript rather than CSS because the Chrome
     * PHP library can pre-run scripts on page creation, which lets us place the
     * style tag as early as possible.
     */
    public function browserPreScript(): string
    {
        return <<<'JS'
(function () {
    const styleId = 'local-visual-diff-motion-reset';
    const existing = document.getElementById(styleId);

    if (existing) {
        return;
    }

    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
        *,
        *::before,
        *::after {
            animation: none !important;
            transition: none !important;
            caret-color: transparent !important;
            scroll-behavior: auto !important;
        }
    `;

    (document.head || document.documentElement).appendChild(style);
})();
JS;
    }

    /**
     * Apply the same motion reset to an already loaded page.
     *
     * This is a second layer of protection for navigation flows where the page
     * pre-script is not enough on its own.
     */
    public function injectInto(Page $page, ?int $timeoutMs = null): void
    {
        $page->evaluate($this->browserPreScript())->waitForResponse($timeoutMs);
    }
}
