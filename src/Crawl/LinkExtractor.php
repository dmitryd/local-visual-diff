<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Extract raw href values from the baseline homepage.
 *
 * The extractor intentionally stays narrow: it fetches the homepage HTML and
 * returns anchor href values. URL filtering and normalization are handled by
 * the mapper and queue factory so those rules are easier to test in isolation.
 */
class LinkExtractor
{
    /**
     * Fetch homepage HTML and return raw href values from anchor tags.
     *
     * @return array<int, string>
     */
    public function extract(string $baselineBaseUrl): array
    {
        $html = $this->fetchHomepageHtml($baselineBaseUrl);

        return $this->extractFromHtml($html);
    }

    /**
     * Fetch the homepage HTML from the network.
     *
     * Tests can override this method to provide fixture HTML without making
     * any network requests.
     */
    protected function fetchHomepageHtml(string $baselineBaseUrl): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
            'https' => [
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
        ]);

        $html = @file_get_contents($baselineBaseUrl, false, $context);

        if ($html === false) {
            throw new RuntimeException(sprintf('Could not fetch homepage HTML from %s', $baselineBaseUrl));
        }

        return $html;
    }

    /**
     * Extract href attributes from a block of homepage HTML.
     *
     * @return array<int, string>
     */
    public function extractFromHtml(string $html): array
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//a[@href]');

        if ($nodes === false) {
            return [];
        }

        $hrefs = [];

        foreach ($nodes as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);

            if ($href === '') {
                continue;
            }

            $hrefs[] = $href;
        }

        return $hrefs;
    }
}
