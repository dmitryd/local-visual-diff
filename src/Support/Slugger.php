<?php

declare(strict_types=1);

namespace LocalVisualDiff\Support;

/**
 * Convert normalized page targets into safe directory names.
 *
 * Query-string variants are valid crawl targets in phase 2, so a plain
 * punctuation-stripping slug is no longer unique enough on its own. Appending a
 * short hash keeps artifact directories stable and collision-resistant while
 * still leaving the slug human-readable.
 */
final class Slugger
{
    public function slugPath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return 'home';
        }

        $slug = strtolower($path);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        $slug = $slug === '' ? 'page' : $slug;

        return sprintf('%s-%s', $slug, substr(sha1($path), 0, 8));
    }
}
