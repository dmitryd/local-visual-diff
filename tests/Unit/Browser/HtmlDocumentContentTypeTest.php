<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Browser;

use LocalVisualDiff\Browser\HtmlDocumentContentType;
use PHPUnit\Framework\TestCase;

final class HtmlDocumentContentTypeTest extends TestCase
{
    public function testItRecognizesHtmlContentTypes(): void
    {
        self::assertTrue(HtmlDocumentContentType::isHtml('text/html; charset=utf-8'));
        self::assertTrue(HtmlDocumentContentType::isHtml('application/xhtml+xml'));
        self::assertTrue(HtmlDocumentContentType::isHtml('', 'text/html'));
    }

    public function testItRecognizesKnownNonHtmlContentTypes(): void
    {
        self::assertTrue(HtmlDocumentContentType::isKnownNonHtml(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ));
        self::assertTrue(HtmlDocumentContentType::isKnownNonHtml('application/pdf'));
        self::assertTrue(HtmlDocumentContentType::isKnownNonHtml('application/octet-stream'));
    }

    public function testItDoesNotTreatMissingContentTypeAsKnownNonHtml(): void
    {
        self::assertFalse(HtmlDocumentContentType::isKnownNonHtml('', ''));
        self::assertSame('', HtmlDocumentContentType::preferredType('', ''));
    }
}
