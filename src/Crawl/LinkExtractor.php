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
    public function extract(string $pageUrl, bool $verifyTls = true): array
    {
        $html = $this->fetchHtml($pageUrl, $verifyTls);

        return $this->extractFromHtml($html);
    }

    /**
     * Fetch the page HTML from the network with cURL.
     *
     * Tests can override this method to provide fixture HTML without making
     * any network requests.
     */
    protected function fetchHtml(string $pageUrl, bool $verifyTls = true): string
    {
        $handle = curl_init($pageUrl);

        if ($handle === false) {
            throw new RuntimeException(sprintf('Could not initialize cURL for %s', $pageUrl));
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        ]);

        $html = curl_exec($handle);

        if ($html === false) {
            $errorNumber = curl_errno($handle);
            $errorMessage = curl_error($handle);
            curl_close($handle);

            if (!$verifyTls && $errorMessage !== '') {
                throw new RuntimeException(sprintf('Could not fetch page HTML from %s: %s', $pageUrl, $errorMessage));
            }

            if ($verifyTls && $errorNumber === 60) {
                throw new RuntimeException(sprintf(
                    'Could not fetch page HTML from %s: %s. If this is a local development certificate, try config/local.ini with [crawl] fetch_verify_tls = false or pass --insecure-crawl-fetch.',
                    $pageUrl,
                    $errorMessage
                ));
            }

            throw new RuntimeException(sprintf('Could not fetch page HTML from %s: %s', $pageUrl, $errorMessage));
        }

        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($httpStatus >= 400) {
            throw new RuntimeException(sprintf('Could not fetch page HTML from %s: HTTP %d', $pageUrl, $httpStatus));
        }

        return is_string($html) ? $html : '';
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
