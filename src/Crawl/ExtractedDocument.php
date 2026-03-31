<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

/**
 * Describe one fetched HTML document for crawl-time link discovery.
 *
 * The crawler needs more than raw href values when it wants to resolve links
 * the same way a browser does. In particular, redirect chains can change the
 * document URL, and a `<base href="...">` element can override how relative
 * links should be interpreted. This small value object keeps those pieces of
 * context together so the crawler can make one consistent decision about the
 * effective base URL for the document.
 */
final class ExtractedDocument
{
    /**
     * @param array<int, string> $hrefs
     */
    public function __construct(
        public readonly array $hrefs,
        public readonly string $finalUrl,
        public readonly string $baseHref,
    ) {
    }
}
