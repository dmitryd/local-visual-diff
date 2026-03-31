<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Integration;

use LocalVisualDiff\Crawl\LinkExtractor;
use LocalVisualDiff\Crawl\PageQueueFactory;
use LocalVisualDiff\Crawl\UrlMapper;
use LocalVisualDiff\Crawl\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class CrawlHomepageLinksCommandTest extends TestCase
{
    public function testHomepageLinksProduceQueueWithHomepageAndSameOriginPaths(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/">Home</a>
    <a href="/about/">About</a>
    <a href="http://legacy.local/contact?ref=home">Contact</a>
    <a href="http://legacy.local/pricing/#plans">Pricing</a>
    <a href="team">Team</a>
    <a href="/about#team">About duplicate</a>
    <a href="https://twitter.com/example">Twitter</a>
    <a href="mailto:hello@example.com">Mail</a>
    <a href="/assets/app.js">JS asset</a>
  </body>
</html>
HTML;

        $factory = new PageQueueFactory(new FixtureLinkExtractor($html), new UrlNormalizer(), new UrlMapper());

        self::assertSame(
            ['/', '/about', '/contact', '/pricing', '/team'],
            $factory->build('http://legacy.local/', true, 0)
        );
    }

    public function testCrawlCanBeLimitedToASmallNumberOfPages(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/about">About</a>
    <a href="/blog">Blog</a>
    <a href="/contact">Contact</a>
  </body>
</html>
HTML;

        $factory = new PageQueueFactory(new FixtureLinkExtractor($html), new UrlNormalizer(), new UrlMapper());

        self::assertSame(['/', '/about'], $factory->build('http://legacy.local', true, 2));
    }
}

final class FixtureLinkExtractor extends LinkExtractor
{
    public function __construct(private readonly string $html)
    {
    }

    protected function fetchHomepageHtml(string $baselineBaseUrl): string
    {
        return $this->html;
    }
}
