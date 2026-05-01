<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

use RuntimeException;

/**
 * Normalize URLs so path joining and comparison remain deterministic.
 */
final class UrlNormalizer
{
    /**
     * Normalize a base URL by trimming surrounding whitespace and keeping only
     * its origin.
     *
     * The tool accepts both `http://example.local` and `http://example.local/`
     * from the CLI. It also accepts accidental paths such as
     * `http://example.local/de/`, but those paths are stripped because the CLI
     * arguments are base URLs, not crawl-start URLs.
     */
    public function normalizeBaseUrl(string $url): string
    {
        return $this->originFromUrl($url);
    }

    public function hasNonOriginUrlParts(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts)) {
            return false;
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? trim($parts['path']) : '';
        $pathIsNonRoot = $path !== '' && $path !== '/';

        return $pathIsNonRoot
            || isset($parts['query'])
            || isset($parts['fragment']);
    }

    public function discardedUrlSuffix(string $url): string
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts)) {
            return '';
        }

        $suffix = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';

        if (isset($parts['query']) && is_string($parts['query'])) {
            $suffix .= '?' . $parts['query'];
        }

        if (isset($parts['fragment']) && is_string($parts['fragment'])) {
            $suffix .= '#' . $parts['fragment'];
        }

        return $suffix;
    }

    private function originFromUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException(sprintf('Invalid base URL: %s', $url));
        }

        $origin = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . (string) $parts['port'];
        }

        return $origin;
    }
}
