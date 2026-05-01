<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Crawl;

use LocalVisualDiff\Crawl\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    public function testBaseUrlNormalizationKeepsOnlyOrigin(): void
    {
        $normalizer = new UrlNormalizer();

        self::assertSame('http://legacy.local', $normalizer->normalizeBaseUrl('http://legacy.local/'));
        self::assertSame('http://legacy.local:8080', $normalizer->normalizeBaseUrl('http://legacy.local:8080/'));
        self::assertSame('http://legacy.local', $normalizer->normalizeBaseUrl(' http://legacy.local/path/ '));
        self::assertSame('https://legacy.local', $normalizer->normalizeBaseUrl('https://legacy.local/de/?foo=bar#top'));
    }

    public function testDetectsAndReportsDiscardedUrlParts(): void
    {
        $normalizer = new UrlNormalizer();

        self::assertFalse($normalizer->hasNonOriginUrlParts('http://legacy.local/'));
        self::assertTrue($normalizer->hasNonOriginUrlParts('http://legacy.local/de/'));
        self::assertTrue($normalizer->hasNonOriginUrlParts('http://legacy.local/?foo=bar'));
        self::assertSame('/de/?foo=bar#top', $normalizer->discardedUrlSuffix('http://legacy.local/de/?foo=bar#top'));
    }
}
