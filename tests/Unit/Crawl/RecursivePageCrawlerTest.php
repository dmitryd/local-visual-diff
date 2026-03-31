<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Crawl;

use LocalVisualDiff\Crawl\LinkExtractor;
use LocalVisualDiff\Crawl\RecursivePageCrawler;
use LocalVisualDiff\Crawl\UrlMapper;
use LocalVisualDiff\Crawl\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class RecursivePageCrawlerTest extends TestCase
{
    public function testCrawlerTraversesDiscoveredPagesBreadthFirst(): void
    {
        $crawler = new RecursivePageCrawler(
            new FixtureRecursiveLinkExtractor([
                'http://legacy.local' => '<a href="/about">About</a><a href="/products?page=2">Products</a>',
                'http://legacy.local/about' => '<a href="/contact">Contact</a>',
                'http://legacy.local/products?page=2' => '<a href="/about/">About again</a>',
                'http://legacy.local/contact' => '<a href="mailto:hello@example.com">Mail</a>',
            ]),
            new UrlMapper(),
            new UrlNormalizer()
        );

        self::assertSame(
            ['/', '/about', '/products?page=2', '/contact'],
            $crawler->crawl('http://legacy.local', '', true, 0)
        );
    }

    public function testCrawlerResolvesRelativeLinksAgainstRedirectedDocumentUrl(): void
    {
        $crawler = new RecursivePageCrawler(
            new FixtureRecursiveLinkExtractor([
                'http://legacy.local' => [
                    'html' => '<a href="about">About</a>',
                    'finalUrl' => 'http://legacy.local/en/',
                ],
                'http://legacy.local/en/about' => [
                    'html' => '',
                    'finalUrl' => 'http://legacy.local/en/about',
                ],
            ]),
            new UrlMapper(),
            new UrlNormalizer()
        );

        self::assertSame(
            ['/', '/en/about'],
            $crawler->crawl('http://legacy.local', '', true, 0)
        );
    }

    public function testCrawlerUsesDocumentBaseHrefWhenResolvingRelativeLinks(): void
    {
        $crawler = new RecursivePageCrawler(
            new FixtureRecursiveLinkExtractor([
                'http://legacy.local' => [
                    'html' => '<base href="/de/"><a href="contact">Contact</a>',
                    'finalUrl' => 'http://legacy.local/en/',
                ],
                'http://legacy.local/de/contact' => [
                    'html' => '',
                    'finalUrl' => 'http://legacy.local/de/contact',
                ],
            ]),
            new UrlMapper(),
            new UrlNormalizer()
        );

        self::assertSame(
            ['/', '/de/contact'],
            $crawler->crawl('http://legacy.local', '', true, 0)
        );
    }

    public function testCrawlerDeduplicatesTargetsAfterIgnoringConfiguredQueryParameters(): void
    {
        $crawler = new RecursivePageCrawler(
            new FixtureRecursiveLinkExtractor([
                'http://legacy.local' => '<a href="/search/?tx_solr[q]=one&category=cards">First</a><a href="/search/?tx_solr[q]=two&category=cards">Second</a>',
                'http://legacy.local/search?category=cards' => '',
            ]),
            new UrlMapper(),
            new UrlNormalizer()
        );

        self::assertSame(
            ['/', '/search?category=cards'],
            $crawler->crawl('http://legacy.local', '/^tx_solr\\[/', true, 0)
        );
    }

    public function testMaxPagesStopsQueueGrowth(): void
    {
        $crawler = new RecursivePageCrawler(
            new FixtureRecursiveLinkExtractor([
                'http://legacy.local' => '<a href="/about">About</a><a href="/contact">Contact</a>',
                'http://legacy.local/about' => '<a href="/pricing">Pricing</a>',
            ]),
            new UrlMapper(),
            new UrlNormalizer()
        );

        self::assertSame(['/', '/about'], $crawler->crawl('http://legacy.local', '', true, 2));
    }
}

final class FixtureRecursiveLinkExtractor extends LinkExtractor
{
    /**
     * @param array<string, string|array{html:string, finalUrl?:string}> $documentsByUrl
     */
    public function __construct(private readonly array $documentsByUrl)
    {
    }

    protected function fetchDocument(string $pageUrl, bool $verifyTls = true): array
    {
        unset($verifyTls);

        $document = $this->documentsByUrl[$pageUrl] ?? '';

        if (is_array($document)) {
            return [
                'html' => $document['html'],
                'finalUrl' => $document['finalUrl'] ?? $pageUrl,
            ];
        }

        return [
            'html' => (string) $document,
            'finalUrl' => $pageUrl,
        ];
    }
}
