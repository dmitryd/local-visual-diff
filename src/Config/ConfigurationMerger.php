<?php

declare(strict_types=1);

namespace LocalVisualDiff\Config;

/**
 * Merge configuration layers with later values winning over earlier ones.
 *
 * The loader uses this helper so the precedence rules stay explicit:
 * defaults.ini < local.ini < CLI overrides.
 */
final class ConfigurationMerger
{
    /**
     * Merge an ordered list of associative configuration arrays.
     */
    public function merge(array ...$layers): array
    {
        $result = [];

        foreach ($layers as $layer) {
            $result = $this->mergeLayer($result, $layer);
        }

        return $result;
    }

    /**
     * Recursively merge one layer into the accumulated configuration.
     */
    private function mergeLayer(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $base)
                && is_array($base[$key])
                && is_array($value)
                && !$this->isList($base[$key])
                && !$this->isList($value)
            ) {
                $base[$key] = $this->mergeLayer($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * PHP 8.3 has array_is_list, but this wrapper keeps the intent obvious at
     * the merge site and makes the code easier to read in tests.
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
