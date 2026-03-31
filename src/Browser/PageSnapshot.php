<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

/**
 * Immutable data captured from one rendered page.
 */
final class PageSnapshot
{
    public function __construct(
        public readonly string $pageUrl,
        public readonly string $title,
        public readonly int $httpStatus,
        public readonly string $imagePath,
        public readonly int $pageHeight
    ) {
    }
}
