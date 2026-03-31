<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

/**
 * Convert crawl-time URLs into normalized comparison paths.
 *
 * This class keeps the crawl rules in one place:
 * - accept only http/https URLs that belong to the same origin as the
 *   baseline homepage
 * - ignore fragments and query strings for the first version
 * - normalize paths so `/about/`, `about`, and `/about#team` all become
 *   the same canonical path
 */
final class UrlMapper
{
    /**
     * Normalize a base URL by trimming whitespace and dropping a trailing slash.
     */
    public function normalizeBaseUrl(string $url): string
    {
        $trimmed = trim($url);

        return rtrim($trimmed, '/');
    }

    /**
     * Normalize a path so the crawl queue can deduplicate equivalent entries.
     */
    public function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        $path = $this->stripQueryAndFragment($path);

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $path = $this->collapseDotSegments($path);
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Turn a raw href into a normalized path or return null if it should not
     * be crawled.
     */
    public function toPath(string $baselineBaseUrl, string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || $this->isIgnoredScheme($href) || str_starts_with($href, '#')) {
            return null;
        }

        $resolvedUrl = $this->resolveUrl($baselineBaseUrl, $href);

        if ($resolvedUrl === null || !$this->isSameOrigin($baselineBaseUrl, $resolvedUrl)) {
            return null;
        }

        $path = parse_url($resolvedUrl, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        if ($this->isIgnoredAssetPath($path)) {
            return null;
        }

        return $this->normalizePath($path);
    }

    /**
     * Resolve a href against a baseline URL.
     */
    public function resolveUrl(string $baselineBaseUrl, string $href): ?string
    {
        $href = trim($href);

        if ($href === '') {
            return null;
        }

        $href = $this->stripFragment($href);

        if ($this->isIgnoredScheme($href)) {
            return null;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $baseParts = parse_url($baselineBaseUrl);

        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }

        $scheme = (string) $baseParts['scheme'];
        $authority = $this->buildAuthority($baseParts);

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $authority . $href;
        }

        $basePath = $baseParts['path'] ?? '/';
        $directory = $this->baseDirectory((string) $basePath);
        $combinedPath = $this->normalizeRelativePath($directory . $href);

        return $scheme . '://' . $authority . $combinedPath;
    }

    /**
     * Decide whether a url belongs to the same origin as the baseline site.
     */
    public function isSameOrigin(string $baselineBaseUrl, string $url): bool
    {
        $baselineParts = parse_url($baselineBaseUrl);
        $urlParts = parse_url($url);

        if (!is_array($baselineParts) || !is_array($urlParts)) {
            return false;
        }

        $baselineScheme = strtolower((string) ($baselineParts['scheme'] ?? ''));
        $baselineHost = strtolower((string) ($baselineParts['host'] ?? ''));
        $baselinePort = $this->normalizePort($baselineParts, $baselineScheme);

        $urlScheme = strtolower((string) ($urlParts['scheme'] ?? ''));
        $urlHost = strtolower((string) ($urlParts['host'] ?? ''));
        $urlPort = $this->normalizePort($urlParts, $urlScheme);

        return $baselineScheme !== ''
            && $baselineHost !== ''
            && $baselineScheme === $urlScheme
            && $baselineHost === $urlHost
            && $baselinePort === $urlPort;
    }

    private function isIgnoredScheme(string $href): bool
    {
        $lower = strtolower($href);

        return str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')
            || str_starts_with($lower, 'javascript:');
    }

    private function isIgnoredAssetPath(string $path): bool
    {
        $lowerPath = strtolower($this->stripQueryAndFragment($path));
        $extension = pathinfo($lowerPath, PATHINFO_EXTENSION);

        if ($extension === '') {
            return false;
        }

        return in_array($extension, [
            'css',
            'gif',
            'ico',
            'jpeg',
            'jpg',
            'js',
            'map',
            'mjs',
            'pdf',
            'png',
            'svg',
            'webp',
            'woff',
            'woff2',
            'ttf',
            'otf',
            'zip',
            'gz',
            'mp4',
            'mp3',
            'avi',
            'mov',
            'webm',
        ], true);
    }

    private function stripFragment(string $url): string
    {
        $hashPosition = strpos($url, '#');

        if ($hashPosition === false) {
            return $url;
        }

        return substr($url, 0, $hashPosition);
    }

    private function stripQueryAndFragment(string $path): string
    {
        $path = $this->stripFragment($path);
        $queryPosition = strpos($path, '?');

        if ($queryPosition === false) {
            return $path;
        }

        return substr($path, 0, $queryPosition);
    }

    private function buildAuthority(array $parts): string
    {
        $authority = (string) $parts['host'];

        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority;
    }

    private function normalizePort(array $parts, string $scheme): string
    {
        if (isset($parts['port'])) {
            return (string) $parts['port'];
        }

        return $scheme === 'https' ? '443' : '80';
    }

    private function baseDirectory(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (str_ends_with($path, '/')) {
            return $path;
        }

        $directory = dirname($path);

        if ($directory === '.' || $directory === DIRECTORY_SEPARATOR) {
            return '/';
        }

        return rtrim($directory, '/') . '/';
    }

    private function normalizeRelativePath(string $path): string
    {
        $segments = explode('/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($stack);
                continue;
            }

            $stack[] = $segment;
        }

        return '/' . implode('/', $stack);
    }

    private function collapseDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($stack);
                continue;
            }

            $stack[] = $segment;
        }

        $normalized = '/' . implode('/', $stack);

        return $normalized === '' ? '/' : $normalized;
    }
}
