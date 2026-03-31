<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Crawl;

use LocalVisualDiff\Crawl\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    public function testBaseUrlNormalizationRemovesTrailingSlash(): void
    {
        $normalizer = new UrlNormalizer();

        self::assertSame('http://legacy.local', $normalizer->normalizeBaseUrl('http://legacy.local/'));
        self::assertSame('http://legacy.local:8080', $normalizer->normalizeBaseUrl('http://legacy.local:8080/'));
        self::assertSame('http://legacy.local/path', $normalizer->normalizeBaseUrl(' http://legacy.local/path/ '));
    }
}
