<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Crawl;

use LocalVisualDiff\Crawl\LinkExtractor;
use LocalVisualDiff\Crawl\PageQueueFactory;
use LocalVisualDiff\Crawl\UrlMapper;
use LocalVisualDiff\Crawl\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class PageQueueFactoryTest extends TestCase
{
    public function testCrawlDisabledReturnsHomepageOnly(): void
    {
        $factory = new PageQueueFactory(new FixtureLinkExtractor('<html></html>'), new UrlNormalizer(), new UrlMapper());

        self::assertSame(['/'], $factory->build('http://legacy.local/', false, 0));
    }

    public function testCrawlEnabledNormalizesAndDeduplicatesHomepageLinks(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/about/">About</a>
    <a href="http://legacy.local/about#team">About duplicate</a>
    <a href="contact?ref=home">Contact</a>
    <a href="https://twitter.com/example">External</a>
    <a href="mailto:hello@example.com">Mail</a>
  </body>
</html>
HTML;

        $factory = new PageQueueFactory(new FixtureLinkExtractor($html), new UrlNormalizer(), new UrlMapper());

        self::assertSame(['/', '/about', '/contact'], $factory->build('http://legacy.local/', true, 0));
    }

    public function testMaxPagesCapsTheSortedQueue(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/about">About</a>
    <a href="/contact">Contact</a>
    <a href="/pricing">Pricing</a>
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
