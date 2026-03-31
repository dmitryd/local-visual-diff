<?php

declare(strict_types=1);

namespace LocalVisualDiff\Support;

/**
 * Convert page paths into safe directory names.
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

        return $slug === '' ? 'page' : $slug;
    }
}
