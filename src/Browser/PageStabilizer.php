<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

use HeadlessChromium\Page;

/**
 * Inject a small stabilization layer that disables motion and neutralizes video.
 *
 * This reduces false positives caused by timing differences between pages.
 * Background video is especially problematic for screenshot comparison because
 * two captures can land on different frames even when the underlying page is
 * otherwise identical. We therefore disable video playback by default as part
 * of the same stabilization step.
 */
final class PageStabilizer
{
    private const STYLE_ID = 'local-visual-diff-motion-reset';

    /**
     * Build the JavaScript snippet that injects a style tag into the page and
     * freezes any HTML video elements.
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

        video {
            visibility: hidden !important;
        }
    `;

    (document.head || document.documentElement).appendChild(style);

    const disableVideo = (video) => {
        try {
            video.pause();
            video.muted = true;
            video.autoplay = false;
            video.loop = false;

            if (typeof video.currentTime === 'number') {
                video.currentTime = 0;
            }
        } catch (error) {
            // Some players protect media state mutations. That should not abort
            // stabilization because hiding the element still removes frame drift.
        }
    };

    document.querySelectorAll('video').forEach(disableVideo);

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (!(node instanceof Element)) {
                    continue;
                }

                if (node.tagName === 'VIDEO') {
                    disableVideo(node);
                    continue;
                }

                node.querySelectorAll?.('video').forEach(disableVideo);
            }
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
    });
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
