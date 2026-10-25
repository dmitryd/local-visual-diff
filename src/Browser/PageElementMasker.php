<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

use HeadlessChromium\Page;

/**
 * This class contains a browser helper that masks selected page regions.
 *
 * It creates opaque overlays without changing the selected elements, keeping
 * their dimensions and surrounding layout available to visual comparison.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
final class PageElementMasker
{
    protected const MASK_COLOR = '#808080';

    protected const MASK_LAYER_ID = 'local-visual-diff-mask-layer';

    /**
     * Apply the configured element masks to an already rendered page.
     */
    public function injectInto(Page $page, string $selector, ?int $timeoutMs = null): void
    {
        $script = $this->browserScript($selector);

        if ($script === '') {
            return;
        }

        $result = $page->evaluate($script)->getReturnValue($timeoutMs);

        if (!is_array($result) || !is_string($result['error'] ?? null)) {
            return;
        }

        if (($result['errorType'] ?? '') === 'invalid-selector') {
            throw new InvalidMaskSelectorException($result['error']);
        }

        throw new PageMaskingException($result['error']);
    }

    /**
     * Build the JavaScript that covers each matched rendered rectangle.
     */
    public function browserScript(string $selector): string
    {
        $selector = trim($selector);

        if ($selector === '') {
            return '';
        }

        $selectorJson = json_encode($selector, JSON_THROW_ON_ERROR);
        $maskColorJson = json_encode(self::MASK_COLOR, JSON_THROW_ON_ERROR);
        $maskLayerIdJson = json_encode(self::MASK_LAYER_ID, JSON_THROW_ON_ERROR);

        return <<<JS
(function () {
    const selector = {$selectorJson};
    const maskColor = {$maskColorJson};
    const maskLayerId = {$maskLayerIdJson};
    const documentDimensions = () => ({
        width: Math.max(
            document.documentElement.clientWidth,
            document.documentElement.scrollWidth,
            document.body ? document.body.scrollWidth : 0
        ),
        height: Math.max(
            document.documentElement.clientHeight,
            document.documentElement.scrollHeight,
            document.body ? document.body.scrollHeight : 0
        ),
    });
    let elements;

    document.getElementById(maskLayerId)?.remove();
    const originalDimensions = documentDimensions();
    const documentWidth = originalDimensions.width;
    const documentHeight = originalDimensions.height;

    try {
        elements = Array.from(document.querySelectorAll(selector));
    } catch (error) {
        const detail = error instanceof Error ? error.message : String(error);

        return {
            errorType: 'invalid-selector',
            error: 'Invalid mask selector "' + selector + '": ' + detail,
        };
    }

    if (elements.length === 0) {
        return;
    }

    const layer = document.createElement('div');
    layer.id = maskLayerId;
    layer.setAttribute('aria-hidden', 'true');
    Object.assign(layer.style, {
        position: 'absolute',
        left: '0',
        top: '0',
        width: documentWidth + 'px',
        height: documentHeight + 'px',
        overflow: 'hidden',
        contain: 'strict',
        pointerEvents: 'none',
        zIndex: '2147483647',
    });

    for (const element of elements) {
        const bounds = element.getBoundingClientRect();

        const left = Math.max(0, bounds.left + window.scrollX);
        const top = Math.max(0, bounds.top + window.scrollY);
        const right = Math.min(documentWidth, bounds.right + window.scrollX);
        const bottom = Math.min(documentHeight, bounds.bottom + window.scrollY);

        if (right <= left || bottom <= top) {
            continue;
        }

        const mask = document.createElement('div');
        Object.assign(mask.style, {
            position: 'absolute',
            left: left + 'px',
            top: top + 'px',
            width: (right - left) + 'px',
            height: (bottom - top) + 'px',
            boxSizing: 'border-box',
            background: maskColor,
            border: '0',
            borderRadius: '0',
            boxShadow: 'none',
            margin: '0',
            padding: '0',
        });
        layer.appendChild(mask);
    }

    document.documentElement.appendChild(layer);

    const maskedDimensions = documentDimensions();

    if (
        maskedDimensions.width !== documentWidth
        || maskedDimensions.height !== documentHeight
    ) {
        layer.remove();

        return {
            errorType: 'geometry',
            error: 'Mask layer changed document geometry from '
                + documentWidth + 'x' + documentHeight + ' to '
                + maskedDimensions.width + 'x' + maskedDimensions.height + '.',
        };
    }
})();
JS;
    }
}
