<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

/**
 * Normalize URLs so path joining and comparison remain deterministic.
 */
final class UrlNormalizer
{
    /**
     * Normalize a base URL by trimming surrounding whitespace and removing a
     * trailing slash.
     *
     * The tool accepts both `http://example.local` and `http://example.local/`
     * from the CLI. Internally we store the slashless form so later path joins
     * do not accidentally produce double slashes.
     */
    public function normalizeBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
