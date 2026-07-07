<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Crawl;

use LocalVisualDiff\Crawl\UrlMapper;
use PHPUnit\Framework\TestCase;

final class UrlMapperTest extends TestCase
{
    public function testToTargetNormalizesSameOriginUrlsAndPreservesQueryStrings(): void
    {
        $mapper = new UrlMapper();

        self::assertSame('/about', $mapper->toTarget('http://legacy.local', 'http://legacy.local', '/about/'));
        self::assertSame('/about', $mapper->toTarget('http://legacy.local', 'http://legacy.local', 'http://legacy.local/about#team'));
        self::assertSame(
            '/contact?ref=home',
            $mapper->toTarget('http://legacy.local', 'http://legacy.local/about', '../contact?ref=home')
        );
        self::assertSame(
            '/about?page=2',
            $mapper->toTarget('http://legacy.local', 'http://legacy.local/about', '?page=2')
        );
    }

    public function testToTargetCanStripIgnoredQueryParametersDuringNormalization(): void
    {
        $mapper = new UrlMapper();

        self::assertSame(
            '/search?category=cards',
            $mapper->toTarget(
                'http://legacy.local',
                'http://legacy.local',
                '/search/?category=cards&tx_solr[q]=123',
                '/^tx_solr\\[/'
            )
        );
    }

    public function testToTargetRejectsExternalAndSpecialLinks(): void
    {
        $mapper = new UrlMapper();

        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', 'https://twitter.com/example'));
        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', 'mailto:hello@example.com'));
        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', 'javascript:void(0)'));
        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', '#fragment'));
        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', '/assets/site.css'));
        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', '/documents/file.docx'));
        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', '/documents/sheet.xlsx'));
        self::assertNull($mapper->toTarget('http://legacy.local', 'http://legacy.local', '/documents/slides.pptx'));
    }

    public function testNormalizeTargetCollapsesRedundantSlashesDotsAndSortsQueries(): void
    {
        $mapper = new UrlMapper();

        self::assertSame('/about', $mapper->normalizeTarget('/about/'));
        self::assertSame('/about/team', $mapper->normalizeTarget('/about/./team/'));
        self::assertSame('/team', $mapper->normalizeTarget('/about/../team/'));
        self::assertSame('/search?a=1&b=2', $mapper->normalizeTarget('/search/?b=2&a=1'));
    }
}
