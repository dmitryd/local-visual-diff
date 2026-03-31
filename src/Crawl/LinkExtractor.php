<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Extract raw href values from a fetched HTML page.
 *
 * The extractor intentionally stays narrow: it fetches one HTML page and
 * returns anchor href values. URL filtering and normalization are handled by
 * the mapper and crawler so those rules are easier to test in isolation.
 */
class LinkExtractor
{
    /**
     * Fetch HTML from a page URL and return raw href values from anchor tags.
     *
     * @return array<int, string>
     */
    public function extract(string $pageUrl): array
    {
        $html = $this->fetchHtml($pageUrl);

        return $this->extractFromHtml($html);
    }

    /**
     * Fetch the page HTML from the network.
     *
     * Tests can override this method to provide fixture HTML without making
     * any network requests.
     */
    protected function fetchHtml(string $pageUrl): string
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

        $html = @file_get_contents($pageUrl, false, $context);

        if ($html === false) {
            throw new RuntimeException(sprintf('Could not fetch page HTML from %s', $pageUrl));
        }

        return $html;
    }

    /**
     * Extract href attributes from a block of HTML.
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
