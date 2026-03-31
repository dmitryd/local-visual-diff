<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

use Throwable;

/**
 * Crawl baseline pages breadth-first and return normalized comparison targets.
 *
 * The crawler discovers pages only from the baseline site. Candidate URLs are
 * never crawled directly; they are derived later by applying the same target to
 * the candidate base URL. This keeps page discovery deterministic and aligned
 * with the original site structure.
 */
final class RecursivePageCrawler
{
    public function __construct(
        private readonly LinkExtractor $linkExtractor = new LinkExtractor(),
        private readonly UrlMapper $urlMapper = new UrlMapper(),
        private readonly UrlNormalizer $urlNormalizer = new UrlNormalizer()
    ) {
    }

    /**
     * Crawl the baseline site starting from `/`.
     *
     * @return array<int, string>
     */
    public function crawl(
        string $baselineBaseUrl,
        string $ignoredQueryParamNamePattern = '',
        bool $fetchVerifyTls = true,
        int $maxPages = 0
    ): array {
        $baselineBaseUrl = $this->urlNormalizer->normalizeBaseUrl($baselineBaseUrl);
        $targets = ['/'];
        $seen = ['/'=> true];

        for ($index = 0; $index < count($targets); $index++) {
            if ($maxPages > 0 && $index >= $maxPages) {
                break;
            }

            $currentTarget = $targets[$index];
            $currentPageUrl = $this->urlMapper->joinBaseUrlAndTarget($baselineBaseUrl, $currentTarget);

            try {
                $hrefs = $this->linkExtractor->extract($currentPageUrl, $fetchVerifyTls);
            } catch (Throwable) {
                continue;
            }

            foreach ($hrefs as $href) {
                $target = $this->urlMapper->toTarget(
                    $baselineBaseUrl,
                    $currentPageUrl,
                    $href,
                    $ignoredQueryParamNamePattern
                );

                if ($target === null || isset($seen[$target])) {
                    continue;
                }

                $seen[$target] = true;
                $targets[] = $target;

                if ($maxPages > 0 && count($targets) >= $maxPages) {
                    break;
                }
            }
        }

        if ($maxPages > 0) {
            return array_slice($targets, 0, $maxPages);
        }

        return $targets;
    }
}
