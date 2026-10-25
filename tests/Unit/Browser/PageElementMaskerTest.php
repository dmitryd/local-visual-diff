<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Browser;

use HeadlessChromium\Page;
use HeadlessChromium\PageUtils\PageEvaluation;
use LocalVisualDiff\Browser\InvalidMaskSelectorException;
use LocalVisualDiff\Browser\PageElementMasker;
use LocalVisualDiff\Browser\PageMaskingException;
use PHPUnit\Framework\TestCase;

/**
 * This class contains tests for the browser-side element masking script.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
final class PageElementMaskerTest extends TestCase
{
    public function testEmptySelectorProducesNoScript(): void
    {
        $masker = new PageElementMasker();

        self::assertSame('', $masker->browserScript(''));
        self::assertSame('', $masker->browserScript('   '));
    }

    public function testScriptMasksRenderedBoundsWithFixedColour(): void
    {
        $script = (new PageElementMasker())->browserScript('img, picture');

        self::assertStringContainsString('document.querySelectorAll(selector)', $script);
        self::assertStringContainsString('getBoundingClientRect()', $script);
        self::assertStringContainsString('window.scrollX', $script);
        self::assertStringContainsString('window.scrollY', $script);
        self::assertStringContainsString('#808080', $script);
        self::assertStringContainsString('local-visual-diff-mask-layer', $script);
        self::assertStringContainsString('Invalid mask selector', $script);
        self::assertStringContainsString("overflow: 'hidden'", $script);
        self::assertStringContainsString('Math.min(documentWidth', $script);
        self::assertStringContainsString('Math.min(documentHeight', $script);
        self::assertStringContainsString('Mask layer changed document geometry', $script);
    }

    public function testSelectorIsEncodedAsJavaScriptData(): void
    {
        $selector = 'img[data-name="quote\'s"]';
        $script = (new PageElementMasker())->browserScript($selector);

        self::assertStringContainsString(
            'const selector = ' . json_encode($selector, JSON_THROW_ON_ERROR) . ';',
            $script
        );
    }

    public function testInvalidSelectorResultBecomesADedicatedException(): void
    {
        $evaluation = $this->createMock(PageEvaluation::class);
        $evaluation
            ->expects(self::once())
            ->method('getReturnValue')
            ->with(30000)
            ->willReturn([
                'errorType' => 'invalid-selector',
                'error' => 'Invalid mask selector "[": invalid selector',
            ]);

        $page = $this->createMock(Page::class);
        $page
            ->expects(self::once())
            ->method('evaluate')
            ->willReturn($evaluation);

        $this->expectException(InvalidMaskSelectorException::class);
        $this->expectExceptionMessage('Invalid mask selector "["');

        (new PageElementMasker())->injectInto($page, '[', 30000);
    }

    public function testGeometryChangeResultBecomesAMaskingException(): void
    {
        $evaluation = $this->createMock(PageEvaluation::class);
        $evaluation
            ->method('getReturnValue')
            ->willReturn([
                'errorType' => 'geometry',
                'error' => 'Mask layer changed document geometry from 1440x6000 to 11000x6000.',
            ]);

        $page = $this->createMock(Page::class);
        $page
            ->method('evaluate')
            ->willReturn($evaluation);

        $this->expectException(PageMaskingException::class);
        $this->expectExceptionMessage('changed document geometry');

        (new PageElementMasker())->injectInto($page, 'img', 30000);
    }
}
