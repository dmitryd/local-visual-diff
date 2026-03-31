<?php

declare(strict_types=1);

namespace LocalVisualDiff\Config;

use RuntimeException;

/**
 * Immutable runtime settings for one comparison run.
 *
 * The object keeps the knobs that shape the comparison engine itself. The
 * baseline and candidate URLs are included so the command layer can hand one
 * complete object to the runner after combining CLI arguments with the shared
 * configuration defaults.
 */
final class RunConfiguration
{
    public function __construct(
        public readonly string $baselineBaseUrl = '',
        public readonly string $candidateBaseUrl = '',
        public readonly ?string $outputDir = null,
        public readonly string $browserPath = '',
        public readonly int $viewportWidth = 1440,
        public readonly int $viewportHeight = 900,
        public readonly int $settleMs = 1000,
        public readonly int $timeoutMs = 30000,
        public readonly int $maxFullpageHeight = 0,
        public readonly int $contextPadding = 200,
        public readonly int $shiftTolerance = 0,
        public readonly int $colorThreshold = 0,
        public readonly float $overlayOpacity = 0.5,
        public readonly bool $crawlHomepageLinks = true,
        public readonly string $ignoredQueryParamNamePattern = '',
        public readonly bool $crawlFetchVerifyTls = false,
        public readonly int $maxPages = 0,
        public readonly bool $captureMatchingScreenshots = false,
        public readonly bool $overwrite = false,
        public readonly bool $verbose = false,
        public readonly bool $veryVerbose = false,
        public readonly bool $profile = false
    ) {
    }

    /**
     * Build a new configuration object from an associative array.
     *
     * The loader returns nested INI sections, so this method is the single
     * place that translates section names and scalar values into strongly typed
     * runtime settings.
     */
    public static function fromArray(
        array $settings,
        string $baselineBaseUrl = '',
        string $candidateBaseUrl = ''
    ): self {
        return new self(
            baselineBaseUrl: $baselineBaseUrl,
            candidateBaseUrl: $candidateBaseUrl,
            outputDir: self::stringValue($settings, ['report', 'output_dir'], null),
            browserPath: self::stringValue($settings, ['browser', 'path'], ''),
            viewportWidth: self::intValue($settings, ['browser', 'viewport_width'], 1440),
            viewportHeight: self::intValue($settings, ['browser', 'viewport_height'], 900),
            settleMs: self::intValue($settings, ['browser', 'settle_ms'], 1000),
            timeoutMs: self::intValue($settings, ['browser', 'timeout_ms'], 30000),
            maxFullpageHeight: self::intValue($settings, ['browser', 'max_fullpage_height'], 0),
            contextPadding: self::intValue($settings, ['browser', 'context_padding'], 200),
            shiftTolerance: self::intValue($settings, ['compare', 'shift_tolerance'], 0),
            colorThreshold: self::intValue($settings, ['compare', 'color_threshold'], 0),
            overlayOpacity: self::floatValue($settings, ['compare', 'overlay_opacity'], 0.5),
            crawlHomepageLinks: self::boolValue($settings, ['crawl', 'crawl_homepage_links'], true),
            ignoredQueryParamNamePattern: self::regexValue($settings, ['crawl', 'ignored_query_param_name_pattern'], ''),
            crawlFetchVerifyTls: self::boolValue($settings, ['crawl', 'fetch_verify_tls'], false),
            maxPages: self::intValue($settings, ['crawl', 'max_pages'], 0),
            captureMatchingScreenshots: self::boolValue($settings, ['run', 'capture_matching_screenshots'], false),
            overwrite: self::boolValue($settings, ['run', 'overwrite'], false)
        );
    }

    /**
     * Read a string from a nested configuration array.
     */
    private static function stringValue(array $settings, array $path, ?string $default): ?string
    {
        $value = self::valueAtPath($settings, $path);

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }

    /**
     * Read an integer from a nested configuration array.
     */
    private static function intValue(array $settings, array $path, int $default): int
    {
        $value = self::valueAtPath($settings, $path);

        return $value === null ? $default : (int) $value;
    }

    /**
     * Read a float from a nested configuration array.
     */
    private static function floatValue(array $settings, array $path, float $default): float
    {
        $value = self::valueAtPath($settings, $path);

        return $value === null ? $default : (float) $value;
    }

    /**
     * Read a boolean from a nested configuration array.
     */
    private static function boolValue(array $settings, array $path, bool $default): bool
    {
        $value = self::valueAtPath($settings, $path);

        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }

    /**
     * Read a regex string and validate that PHP can compile it.
     *
     * The crawler depends on this pattern while normalizing URLs. Validating it
     * here keeps configuration failures obvious and early, instead of surfacing
     * later in the middle of a crawl run.
     */
    private static function regexValue(array $settings, array $path, string $default): string
    {
        $value = self::stringValue($settings, $path, $default) ?? $default;

        if ($value === '') {
            return '';
        }

        if (@preg_match($value, '') === false) {
            throw new RuntimeException(sprintf('Invalid ignored query parameter name pattern: %s', $value));
        }

        return $value;
    }

    /**
     * Walk a nested array by path segments and return the leaf value.
     */
    private static function valueAtPath(array $settings, array $path): mixed
    {
        $current = $settings;

        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
