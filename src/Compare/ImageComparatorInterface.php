<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

/**
 * This interface contains the common contract for screenshot comparison.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
interface ImageComparatorInterface
{
    public function compare(
        string $baselinePath,
        string $candidatePath,
        int $shiftTolerance = 0,
        int $colorThreshold = 0,
        ?string $artifactDirectory = null,
        int $pageHeight = 0,
        int $maxFullpageHeight = 0,
        int $contextPadding = 200
    ): ComparisonResult;
}
