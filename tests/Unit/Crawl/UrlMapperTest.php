<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Crawl;

use LocalVisualDiff\Crawl\UrlMapper;
use PHPUnit\Framework\TestCase;

final class UrlMapperTest extends TestCase
{
    public function testToPathNormalizesSameOriginUrls(): void
    {
        $mapper = new UrlMapper();

        self::assertSame('/about', $mapper->toPath('http://legacy.local', '/about/'));
        self::assertSame('/about', $mapper->toPath('http://legacy.local', 'http://legacy.local/about#team'));
        self::assertSame('/contact', $mapper->toPath('http://legacy.local', 'contact?ref=home'));
    }

    public function testToPathRejectsExternalAndSpecialLinks(): void
    {
        $mapper = new UrlMapper();

        self::assertNull($mapper->toPath('http://legacy.local', 'https://twitter.com/example'));
        self::assertNull($mapper->toPath('http://legacy.local', 'mailto:hello@example.com'));
        self::assertNull($mapper->toPath('http://legacy.local', 'javascript:void(0)'));
        self::assertNull($mapper->toPath('http://legacy.local', '#fragment'));
    }

    public function testNormalizePathCollapsesRedundantSlashesAndDots(): void
    {
        $mapper = new UrlMapper();

        self::assertSame('/about', $mapper->normalizePath('/about/'));
        self::assertSame('/about/team', $mapper->normalizePath('/about/./team/'));
        self::assertSame('/team', $mapper->normalizePath('/about/../team/'));
    }
}
