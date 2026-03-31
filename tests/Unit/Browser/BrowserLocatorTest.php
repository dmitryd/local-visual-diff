<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Browser;

use LocalVisualDiff\Browser\BrowserLocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BrowserLocatorTest extends TestCase
{
    public function testConfiguredPathWinsOverOperatingSystemFallbacks(): void
    {
        $locator = new BrowserLocator([
            '/custom/browser',
            '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
        ], false);

        self::assertSame('/custom/browser', $locator->resolve('/custom/browser', 'Darwin'));
    }

    public function testMacOsFallbacksAreCheckedInOrder(): void
    {
        $locator = new BrowserLocator([
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ], false);

        self::assertSame(
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            $locator->resolve(null, 'Darwin')
        );
    }

    public function testThrowsHelpfulExceptionWhenNoBrowserCanBeFound(): void
    {
        $locator = new BrowserLocator([], false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not find a supported browser executable.');

        $locator->resolve(null, 'Unknown');
    }
}
