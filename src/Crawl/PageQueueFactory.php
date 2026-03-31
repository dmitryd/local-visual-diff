<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

/**
 * Build the ordered list of normalized page targets to compare.
 *
 * The factory keeps the runner decoupled from crawl details. When crawling is
 * disabled it returns the single homepage target. When crawling is enabled it
 * delegates recursive discovery to the dedicated crawler.
 */
final class PageQueueFactory
{
    public function __construct(
        private readonly RecursivePageCrawler $recursivePageCrawler = new RecursivePageCrawler(),
        private readonly UrlNormalizer $urlNormalizer = new UrlNormalizer(),
    ) {
    }

    /**
     * Create the ordered queue of targets to compare.
     *
     * @return array<int, string>
     */
    public function build(
        string $baselineBaseUrl,
        bool $crawlHomepageLinks,
        string $ignoredQueryParamNamePattern,
        int $maxPages
    ): array
    {
        $baselineBaseUrl = $this->urlNormalizer->normalizeBaseUrl($baselineBaseUrl);

        if (!$crawlHomepageLinks) {
            return ['/'];
        }

        return $this->recursivePageCrawler->crawl(
            $baselineBaseUrl,
            $ignoredQueryParamNamePattern,
            $maxPages
        );
    }
}
