<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Config;

use LocalVisualDiff\Config\IniConfigurationLoader;
use PHPUnit\Framework\TestCase;

final class IniConfigurationLoaderTest extends TestCase
{
    private array $tempFiles = [];

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    public function testDefaultsAndLocalIniAreMergedWithCliTakingPriority(): void
    {
        $defaultsPath = $this->createTempIni(<<<'INI'
[browser]
path = "/defaults/browser"
accept_language = "de, en"
mask_selector = ""
viewport_width = 1440
viewport_height = 900
settle_ms = 1000
timeout_ms = 30000
max_fullpage_height = 0
context_padding = 200

[compare]
shift_tolerance = 0
color_threshold = 0
overlay_opacity = 0.5
image_compare_memory_limit = "512M"

[crawl]
crawl_homepage_links = true
ignored_query_param_name_pattern = ""
fetch_verify_tls = false
max_pages = 0

[report]
output_dir = "var/results"
artifact_image_format = "webp"

[run]
capture_matching_screenshots = false
overwrite = false
INI);

        $localPath = $this->createTempIni(<<<'INI'
[browser]
path = "/local/browser"
mask_selector = ".local-mask"
INI);

        $loader = new IniConfigurationLoader();
        $config = $loader->load(
            $defaultsPath,
            $localPath,
            [
                'browser_path' => '/custom/browser',
                'mask_selector' => '.cli-mask',
                'image_compare_memory_limit' => '768M',
            ]
        );

        self::assertSame('/custom/browser', $config->browserPath);
        self::assertSame('de, en', $config->acceptLanguage);
        self::assertSame('.cli-mask', $config->maskSelector);
        self::assertSame(1440, $config->viewportWidth);
        self::assertSame(900, $config->viewportHeight);
        self::assertSame('var/results', $config->outputDir);
        self::assertSame('webp', $config->artifactImageFormat);
        self::assertSame('768M', $config->imageCompareMemoryLimit);
        self::assertTrue($config->crawlHomepageLinks);
        self::assertSame('', $config->ignoredQueryParamNamePattern);
        self::assertFalse($config->crawlFetchVerifyTls);
        self::assertFalse($config->captureMatchingScreenshots);
    }

    public function testInvalidIgnoredQueryPatternFailsEarly(): void
    {
        $defaultsPath = $this->createTempIni(<<<'INI'
[crawl]
ignored_query_param_name_pattern = "/(/"
INI);

        $loader = new IniConfigurationLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid ignored query parameter name pattern');

        $loader->load($defaultsPath);
    }

    private function createTempIni(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lvd-');

        if ($path === false) {
            self::fail('Unable to create a temporary file for INI test data.');
        }

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
