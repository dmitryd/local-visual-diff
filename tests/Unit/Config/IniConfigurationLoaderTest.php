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

[crawl]
crawl_homepage_links = true
ignored_query_param_name_pattern = ""
fetch_verify_tls = false
max_pages = 0

[report]
output_dir = "var/results"

[run]
capture_matching_screenshots = false
overwrite = false
INI);

        $localPath = $this->createTempIni(<<<'INI'
[browser]
path = "/local/browser"
INI);

        $loader = new IniConfigurationLoader();
        $config = $loader->load(
            $defaultsPath,
            $localPath,
            ['browser_path' => '/custom/browser']
        );

        self::assertSame('/custom/browser', $config->browserPath);
        self::assertSame(1440, $config->viewportWidth);
        self::assertSame(900, $config->viewportHeight);
        self::assertSame('var/results', $config->outputDir);
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
