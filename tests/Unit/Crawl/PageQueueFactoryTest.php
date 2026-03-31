<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Crawl;

use LocalVisualDiff\Crawl\LinkExtractor;
use LocalVisualDiff\Crawl\PageQueueFactory;
use LocalVisualDiff\Crawl\RecursivePageCrawler;
use LocalVisualDiff\Crawl\UrlMapper;
use LocalVisualDiff\Crawl\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class PageQueueFactoryTest extends TestCase
{
    public function testCrawlDisabledReturnsHomepageOnly(): void
    {
        $factory = new PageQueueFactory(new RecursivePageCrawler(), new UrlNormalizer());

        self::assertSame(['/'], $factory->build('http://legacy.local/', false, '', true, 0));
    }

    public function testCrawlEnabledDelegatesToRecursiveCrawler(): void
    {
        $factory = new PageQueueFactory(
            new RecursivePageCrawler(
                new FixturePageQueueLinkExtractor([
                    'http://legacy.local' => '<a href="/about">About</a><a href="/search/?tx_solr[q]=one&category=cards">Search</a>',
                    'http://legacy.local/about' => '',
                    'http://legacy.local/search?category=cards' => '',
                ]),
                new UrlMapper(),
                new UrlNormalizer()
            ),
            new UrlNormalizer()
        );

        self::assertSame(
            ['/', '/about', '/search?category=cards'],
            $factory->build('http://legacy.local/', true, '/^tx_solr\\[/', true, 0)
        );
    }
}

final class FixturePageQueueLinkExtractor extends LinkExtractor
{
    /**
     * @param array<string, string> $htmlByUrl
     */
    public function __construct(private readonly array $htmlByUrl)
    {
    }

    protected function fetchHtml(string $pageUrl, bool $verifyTls = true): string
    {
        unset($verifyTls);

        return $this->htmlByUrl[$pageUrl] ?? '';
    }
}
