<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Report;

use LocalVisualDiff\Report\ReportRenderer;
use LocalVisualDiff\Report\ReportViewModelFactory;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the report rendering path end to end so the table markup stays in
 * sync with the data shape expected by later orchestration code.
 */
final class ReportRendererTest extends TestCase
{
    public function testItRendersAUsableReportTable(): void
    {
        $factory = new ReportViewModelFactory();
        $renderer = new ReportRenderer();

        $viewModel = $factory->create(
            [
                'baselineBaseUrl' => 'http://legacy.local',
                'candidateBaseUrl' => 'http://upgraded.local',
                'generatedAt' => '2026-03-31 10:30:00',
                'shiftTolerance' => 3,
                'maskSelector' => 'img.hero',
                'captureMatchingScreenshots' => true,
            ],
            [
                [
                    'path' => '/',
                    'title' => 'Home',
                    'status' => 'matching',
                    'baselineUrl' => 'http://legacy.local',
                    'candidateUrl' => 'http://upgraded.local',
                    'httpStatusBaseline' => 200,
                    'httpStatusCandidate' => 200,
                    'artifacts' => [],
                ],
                [
                    'path' => '/pricing',
                    'title' => 'Pricing',
                    'status' => 'different',
                    'baselineUrl' => 'http://legacy.local/pricing',
                    'candidateUrl' => 'http://upgraded.local/pricing',
                    'httpStatusBaseline' => 200,
                    'httpStatusCandidate' => 200,
                    'artifacts' => [
                        'baseline' => '/tmp/baseline.png',
                        'candidate' => '/tmp/candidate.png',
                        'overlay' => '/tmp/overlay.png',
                        'diff' => '/tmp/diff.png',
                        'context' => '/tmp/context.png',
                    ],
                ],
                [
                    'path' => '/admin',
                    'title' => '',
                    'status' => 'http_error',
                    'baselineUrl' => 'http://legacy.local/admin',
                    'candidateUrl' => 'http://upgraded.local/admin',
                    'httpStatusBaseline' => 500,
                    'httpStatusCandidate' => 200,
                    'artifacts' => [],
                ],
            ]
        );

        $html = $renderer->render($viewModel);

        self::assertStringContainsString('Local Visual Diff Report', $html);
        self::assertStringContainsString('Baseline', $html);
        self::assertStringContainsString('Candidate', $html);
        self::assertStringContainsString('<th>HTTP</th>', $html);
        self::assertStringContainsString('Matching screenshots', $html);
        self::assertStringContainsString('enabled', $html);
        self::assertStringContainsString('matching', $html);
        self::assertStringContainsString('different', $html);
        self::assertStringContainsString('Mask selector', $html);
        self::assertStringContainsString('img.hero', $html);
        self::assertStringContainsString('HTTP error 500', $html);
        self::assertStringContainsString('<span class="http-status">500/200</span>', $html);
        self::assertStringContainsString('Home', $html);
        self::assertStringContainsString('Pricing', $html);
        self::assertStringContainsString('/admin', $html);
        self::assertStringContainsString('Baseline shot', $html);
        self::assertStringContainsString('Candidate shot', $html);
        self::assertStringContainsString('Diff', $html);
        self::assertStringContainsString('Context', $html);
        self::assertStringContainsString('status-dot--matching', $html);
        self::assertStringContainsString('status-dot--different', $html);
        self::assertStringContainsString('status-dot--http-error', $html);
    }
}
