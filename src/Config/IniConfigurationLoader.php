<?php

declare(strict_types=1);

namespace LocalVisualDiff\Config;

use RuntimeException;

/**
 * Load shared settings from INI files and apply the correct precedence.
 *
 * The loader knows how to read the tracked defaults file, optionally layer a
 * local machine-specific override file on top, and finally apply in-memory CLI
 * overrides for the current run.
 */
final class IniConfigurationLoader
{
    public function __construct(
        private readonly ConfigurationMerger $configurationMerger = new ConfigurationMerger()
    ) {
    }

    /**
     * Load the merged configuration and turn it into a runtime object.
     *
     * @param array<string, mixed> $cliOverrides
     */
    public function load(
        string $defaultsPath,
        ?string $localPath = null,
        array $cliOverrides = [],
        string $baselineBaseUrl = '',
        string $candidateBaseUrl = ''
    ): RunConfiguration {
        $defaults = $this->readIniFile($defaultsPath);
        $local = $localPath === null ? [] : $this->readOptionalIniFile($localPath);
        $overrideLayer = $this->normalizeOverrides($cliOverrides);

        $merged = $this->configurationMerger->merge($defaults, $local, $overrideLayer);

        return RunConfiguration::fromArray($merged, $baselineBaseUrl, $candidateBaseUrl);
    }

    /**
     * Read an INI file that must exist.
     *
     * @return array<string, mixed>
     */
    private function readIniFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Configuration file not found: %s', $path));
        }

        $parsed = parse_ini_file($path, true, INI_SCANNER_TYPED);

        if ($parsed === false) {
            throw new RuntimeException(sprintf('Unable to parse configuration file: %s', $path));
        }

        return $parsed;
    }

    /**
     * Read an optional local override file.
     *
     * If the path is provided but missing, that is a configuration error because
     * the caller explicitly requested a local override layer.
     *
     * @return array<string, mixed>
     */
    private function readOptionalIniFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Optional configuration file not found: %s', $path));
        }

        return $this->readIniFile($path);
    }

    /**
     * Convert flat CLI override keys into the same nested shape used by INI
     * sections so the merge step can stay simple.
     *
     * @param array<string, mixed> $cliOverrides
     * @return array<string, mixed>
     */
    private function normalizeOverrides(array $cliOverrides): array
    {
        $nested = [];

        foreach ($cliOverrides as $key => $value) {
            switch ($key) {
                case 'browser_path':
                    $nested['browser']['path'] = $value;
                    break;
                case 'accept_language':
                    $nested['browser']['accept_language'] = $value;
                    break;
                case 'viewport_width':
                case 'viewport_height':
                case 'settle_ms':
                case 'timeout_ms':
                case 'scroll_warmup':
                case 'max_fullpage_height':
                case 'context_padding':
                    $nested['browser'][$key] = $value;
                    break;
                case 'shift_tolerance':
                case 'color_threshold':
                case 'overlay_opacity':
                case 'image_compare_memory_limit':
                    $nested['compare'][$key] = $value;
                    break;
                case 'crawl_homepage_links':
                case 'ignored_query_param_name_pattern':
                case 'fetch_verify_tls':
                case 'max_pages':
                    $nested['crawl'][$key] = $value;
                    break;
                case 'output_dir':
                case 'artifact_image_format':
                    $nested['report'][$key] = $value;
                    break;
                case 'capture_matching_screenshots':
                case 'overwrite':
                    $nested['run'][$key] = $value;
                    break;
                case 'baseline_base_url':
                case 'candidate_base_url':
                    $nested[$key] = $value;
                    break;
                default:
                    $nested[$key] = $value;
                    break;
            }
        }

        return $nested;
    }
}
