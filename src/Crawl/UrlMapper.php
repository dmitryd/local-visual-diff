<?php

declare(strict_types=1);

namespace LocalVisualDiff\Crawl;

/**
 * Convert crawl-time links into normalized comparison targets.
 *
 * A "target" is the canonical path-plus-query string that identifies one page
 * in the crawl queue. The mapper owns the crawl rules so the rest of the code
 * can work with already-normalized targets:
 * - only same-origin http/https links are accepted
 * - obvious asset URLs are rejected
 * - fragments are ignored
 * - query-string variants are preserved unless their parameter names match the
 *   configured ignore pattern
 * - trailing slashes and dot segments are collapsed into one canonical target
 */
final class UrlMapper
{
    /**
     * Resolve a raw href discovered on the current baseline page into a single
     * normalized crawl target.
     */
    public function toTarget(
        string $baselineBaseUrl,
        string $currentPageUrl,
        string $href,
        string $ignoredQueryParamNamePattern = ''
    ): ?string {
        $href = trim($href);

        if ($href === '' || $this->isIgnoredScheme($href) || str_starts_with($href, '#')) {
            return null;
        }

        $resolvedUrl = $this->resolveUrl($currentPageUrl, $href);

        if ($resolvedUrl === null || !$this->isSameOrigin($baselineBaseUrl, $resolvedUrl)) {
            return null;
        }

        $parts = parse_url($this->stripFragment($resolvedUrl));

        if (!is_array($parts)) {
            return null;
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';

        if ($this->isIgnoredAssetPath($path)) {
            return null;
        }

        $query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : null;

        return $this->normalizeTargetFromParts($path, $query, $ignoredQueryParamNamePattern);
    }

    /**
     * Normalize a target string that may already contain a query string.
     */
    public function normalizeTarget(string $target, string $ignoredQueryParamNamePattern = ''): string
    {
        $target = trim($target);

        if ($target === '') {
            return '/';
        }

        $parts = parse_url($this->stripFragment($target));

        if (!is_array($parts)) {
            return '/';
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : null;

        return $this->normalizeTargetFromParts($path, $query, $ignoredQueryParamNamePattern);
    }

    /**
     * Build a fetchable absolute URL for one normalized target.
     */
    public function joinBaseUrlAndTarget(string $baseUrl, string $target): string
    {
        return $target === '/'
            ? rtrim($baseUrl, '/')
            : rtrim($baseUrl, '/') . $target;
    }

    /**
     * Decide which URL should be treated as the document base for relative
     * links discovered in a fetched HTML document.
     *
     * Browser behavior is:
     * - redirects change the effective document URL
     * - `<base href>` overrides relative URL resolution when present
     * - invalid base tags are ignored
     *
     * This helper mirrors that order while keeping a safe fallback to the
     * originally requested URL if anything is missing.
     */
    public function effectiveDocumentBaseUrl(string $requestedUrl, string $finalUrl, string $baseHref): string
    {
        $documentUrl = trim($finalUrl) !== '' ? trim($finalUrl) : $requestedUrl;
        $baseHref = trim($baseHref);

        if ($baseHref === '') {
            return $documentUrl;
        }

        $resolvedBaseUrl = $this->resolveUrl($documentUrl, $baseHref);

        if ($resolvedBaseUrl === null) {
            return $documentUrl;
        }

        return $resolvedBaseUrl;
    }

    /**
     * Resolve a href against the current page URL.
     *
     * Recursive crawling cannot resolve every link against the site root,
     * because relative links on nested pages should be interpreted relative to
     * the current document location.
     */
    public function resolveUrl(string $currentPageUrl, string $href): ?string
    {
        $href = trim($this->stripFragment($href));

        if ($href === '' || $this->isIgnoredScheme($href)) {
            return null;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $baseParts = parse_url($currentPageUrl);

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

        if (str_starts_with($href, '?')) {
            $currentPath = isset($baseParts['path']) && is_string($baseParts['path'])
                ? $baseParts['path']
                : '/';

            return $scheme . '://' . $authority . $this->collapseDotSegments('/' . ltrim($currentPath, '/')) . $href;
        }

        $basePath = $baseParts['path'] ?? '/';
        $directory = $this->baseDirectory((string) $basePath);
        $combinedPath = $this->normalizeRelativePath($directory . $href);

        return $scheme . '://' . $authority . $combinedPath;
    }

    /**
     * Decide whether a URL belongs to the same origin as the baseline site.
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

    /**
     * Normalize path and query components into a canonical crawl target.
     */
    private function normalizeTargetFromParts(
        string $path,
        ?string $query,
        string $ignoredQueryParamNamePattern
    ): string {
        $path = trim($path);

        if ($path === '') {
            $path = '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $path = $this->collapseDotSegments($path);
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $normalizedQuery = $this->normalizeQueryString($query, $ignoredQueryParamNamePattern);

        if ($normalizedQuery === '') {
            return $path === '' ? '/' : $path;
        }

        return ($path === '' ? '/' : $path) . '?' . $normalizedQuery;
    }

    /**
     * Normalize and sort query-string parameters while optionally ignoring
     * machine-generated parameter namespaces configured by the user.
     */
    private function normalizeQueryString(?string $query, string $ignoredQueryParamNamePattern): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $rawPair) {
            if ($rawPair === '') {
                continue;
            }

            $hasEquals = str_contains($rawPair, '=');
            [$rawName, $rawValue] = array_pad(explode('=', $rawPair, 2), 2, '');
            $name = urldecode($rawName);

            if ($name === '' || $this->shouldIgnoreParameterName($name, $ignoredQueryParamNamePattern)) {
                continue;
            }

            $pairs[] = [
                'name' => $name,
                'value' => urldecode($rawValue),
                'hasValue' => $hasEquals,
            ];
        }

        usort(
            $pairs,
            static function (array $left, array $right): int {
                $nameOrder = strcmp($left['name'], $right['name']);

                if ($nameOrder !== 0) {
                    return $nameOrder;
                }

                return strcmp($left['value'], $right['value']);
            }
        );

        $normalizedPairs = [];

        foreach ($pairs as $pair) {
            $encodedName = $this->encodeQueryName($pair['name']);

            if ($pair['hasValue']) {
                $normalizedPairs[] = $encodedName . '=' . rawurlencode($pair['value']);
                continue;
            }

            $normalizedPairs[] = $encodedName;
        }

        return implode('&', $normalizedPairs);
    }

    private function shouldIgnoreParameterName(string $name, string $ignoredQueryParamNamePattern): bool
    {
        if ($ignoredQueryParamNamePattern === '') {
            return false;
        }

        return preg_match($ignoredQueryParamNamePattern, $name) === 1;
    }

    private function encodeQueryName(string $name): string
    {
        return str_replace(['%5B', '%5D'], ['[', ']'], rawurlencode($name));
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
        $lowerPath = strtolower($path);
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

        return match ($scheme) {
            'https' => '443',
            'http' => '80',
            default => '',
        };
    }

    private function baseDirectory(string $path): string
    {
        $pathWithoutQuery = strtok($path, '?');
        $pathWithoutQuery = is_string($pathWithoutQuery) && $pathWithoutQuery !== '' ? $pathWithoutQuery : '/';

        if (str_ends_with($pathWithoutQuery, '/')) {
            return $pathWithoutQuery;
        }

        $lastSlash = strrpos($pathWithoutQuery, '/');

        if ($lastSlash === false) {
            return '/';
        }

        return substr($pathWithoutQuery, 0, $lastSlash + 1);
    }

    private function normalizeRelativePath(string $path): string
    {
        $query = '';
        $questionMark = strpos($path, '?');

        if ($questionMark !== false) {
            $query = substr($path, $questionMark);
            $path = substr($path, 0, $questionMark);
        }

        $normalizedPath = $this->collapseDotSegments('/' . ltrim($path, '/'));

        return $normalizedPath . $query;
    }

    /**
     * Resolve `.` and `..` segments without consulting the filesystem.
     */
    private function collapseDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $collapsed = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($collapsed);
                continue;
            }

            $collapsed[] = $segment;
        }

        return '/' . implode('/', $collapsed);
    }
}
