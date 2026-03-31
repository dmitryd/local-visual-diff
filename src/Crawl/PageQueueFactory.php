<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

/**
 * Build the ordered list of page paths to compare.
 *
 * Phase 1 uses a queue containing only the homepage path (`/`). Phase 2 can
 * enable homepage crawling, at which point this class adds the homepage links,
 * removes duplicates, and applies any page cap configured by the user.
 */
final class PageQueueFactory
{
    public function __construct(
        private readonly LinkExtractor $linkExtractor = new LinkExtractor(),
        private readonly UrlNormalizer $urlNormalizer = new UrlNormalizer(),
        private readonly UrlMapper $urlMapper = new UrlMapper()
    ) {
    }

    /**
     * Create the ordered queue of paths to compare.
     *
     * @return array<int, string>
     */
    public function build(string $baselineBaseUrl, bool $crawlHomepageLinks, int $maxPages): array
    {
        $baselineBaseUrl = $this->urlNormalizer->normalizeBaseUrl($baselineBaseUrl);
        $paths = ['/'];

        if ($crawlHomepageLinks) {
            $hrefs = $this->linkExtractor->extract($baselineBaseUrl);

            foreach ($hrefs as $href) {
                $path = $this->urlMapper->toPath($baselineBaseUrl, $href);

                if ($path === null) {
                    continue;
                }

                $paths[] = $this->urlMapper->normalizePath($path);
            }
        }

        $paths = array_values(array_unique(array_map(
            fn (string $path): string => $this->urlMapper->normalizePath($path),
            $paths
        )));

        sort($paths, SORT_STRING);

        if ($maxPages > 0) {
            $paths = array_slice($paths, 0, $maxPages);
        }

        return $paths;
    }
}
