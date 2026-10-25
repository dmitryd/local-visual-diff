<?php

declare(strict_types=1);

namespace LocalVisualDiff\Run;

/**
 * Run-level summary for one invocation of the visual diff tool.
 */
final class RunSummary
{
    public function __construct(
        public readonly string $baselineBaseUrl,
        public readonly string $candidateBaseUrl,
        public readonly string $generatedAt,
        public readonly string $outputDir,
        public readonly string $reportPath,
        public readonly string $reportJsonPath,
        public readonly int $totalPages,
        public readonly int $matchingPages,
        public readonly int $differentPages,
        public readonly int $httpErrorPages,
        public readonly int $runtimeErrorPages,
        public readonly int $skippedPages,
        public readonly int $shiftTolerance,
        public readonly string $maskSelector,
        public readonly bool $captureMatchingScreenshots
    ) {
    }

    /**
     * Convert the summary to an array for JSON serialization and view model
     * creation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'baselineBaseUrl' => $this->baselineBaseUrl,
            'candidateBaseUrl' => $this->candidateBaseUrl,
            'generatedAt' => $this->generatedAt,
            'outputDir' => $this->outputDir,
            'reportPath' => $this->reportPath,
            'reportJsonPath' => $this->reportJsonPath,
            'totalPages' => $this->totalPages,
            'matchingPages' => $this->matchingPages,
            'differentPages' => $this->differentPages,
            'httpErrorPages' => $this->httpErrorPages,
            'runtimeErrorPages' => $this->runtimeErrorPages,
            'skippedPages' => $this->skippedPages,
            'shiftTolerance' => $this->shiftTolerance,
            'maskSelector' => $this->maskSelector,
            'captureMatchingScreenshots' => $this->captureMatchingScreenshots,
        ];
    }
}
