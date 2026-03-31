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
 * returns anchor href values plus the document context needed to resolve them
 * correctly. URL filtering and normalization are handled by the mapper and
 * crawler so those rules are easier to test in isolation.
 */
class LinkExtractor
{
    /**
     * Fetch one page and return the crawl-time document model.
     *
     * Returning the final fetched URL and the document base href lets the
     * crawler resolve links the same way a browser would after redirects and
     * `<base href>` processing.
     */
    public function extractDocument(string $pageUrl, bool $verifyTls = true): ExtractedDocument
    {
        $response = $this->fetchDocument($pageUrl, $verifyTls);
        $html = $response['html'];

        return new ExtractedDocument(
            $this->extractHrefValues($html),
            trim((string) ($response['finalUrl'] ?? $pageUrl)),
            $this->extractBaseHref($html)
        );
    }

    /**
     * Retain the narrow href-only API for callers that do not care about
     * redirects or document base tags.
     *
     * @return array<int, string>
     */
    public function extract(string $pageUrl, bool $verifyTls = true): array
    {
        return $this->extractDocument($pageUrl, $verifyTls)->hrefs;
    }

    /**
     * Fetch the page HTML from the network with cURL.
     *
     * Tests can override this method to provide fixture HTML without making
     * any network requests.
     *
     * @return array{html:string, finalUrl:string}
     */
    protected function fetchDocument(string $pageUrl, bool $verifyTls = true): array
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
        $finalUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);

        if ($httpStatus >= 400) {
            throw new RuntimeException(sprintf('Could not fetch page HTML from %s: HTTP %d', $pageUrl, $httpStatus));
        }

        return [
            'html' => is_string($html) ? $html : '',
            'finalUrl' => $finalUrl !== '' ? $finalUrl : $pageUrl,
        ];
    }

    /**
     * Extract href attributes from a block of HTML.
     *
     * @return array<int, string>
     */
    public function extractFromHtml(string $html): array
    {
        return $this->extractHrefValues($html);
    }

    /**
     * Parse href values from anchor elements in the document.
     *
     * Keeping DOM parsing in one helper avoids repeating the tolerant libxml
     * setup between href extraction and `<base href>` extraction.
     *
     * @return array<int, string>
     */
    private function extractHrefValues(string $html): array
    {
        $xpath = $this->createXPath($html);
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

    /**
     * Read the first `<base href>` from the HTML document.
     *
     * Browsers use the first base tag for later relative URL resolution. If no
     * valid base tag exists, the crawler should fall back to the fetched
     * document URL.
     */
    private function extractBaseHref(string $html): string
    {
        $xpath = $this->createXPath($html);
        $nodes = $xpath->query('(//base[@href])[1]');

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) $nodes->item(0)?->attributes?->getNamedItem('href')?->nodeValue);
    }

    private function createXPath(string $html): DOMXPath
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
