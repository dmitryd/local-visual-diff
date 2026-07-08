<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use Imagick;
use ImagickPixel;
use LocalVisualDiff\Support\ArtifactImageWriter;
use RuntimeException;

/**
 * Compare two screenshots using exact pixel comparison plus a small spatial
 * tolerance for layout drift.
 *
 * The comparison is intentionally conservative: exact matches are accepted
 * immediately, and when a pixel does not match at the same coordinate the
 * comparator searches nearby coordinates in the baseline image for a pixel of
 * the same color. That lets the tool forgive tiny layout shifts while still
 * flagging real visual differences.
 */
final class ImageComparator
    implements ImageComparatorInterface
{
    public function __construct(
        private readonly ArtifactImageWriter $artifactImageWriter = new ArtifactImageWriter(),
        ?OverlayGenerator $overlayGenerator = null,
        ?DiffImageGenerator $diffImageGenerator = null,
        ?ContextArtifactGenerator $contextArtifactGenerator = null
    ) {
        $this->overlayGenerator = $overlayGenerator ?? new OverlayGenerator($this->artifactImageWriter);
        $this->diffImageGenerator = $diffImageGenerator ?? new DiffImageGenerator($this->artifactImageWriter);
        $this->contextArtifactGenerator = $contextArtifactGenerator ?? new ContextArtifactGenerator(new OverlayGenerator($this->artifactImageWriter));
    }

    private readonly OverlayGenerator $overlayGenerator;

    private readonly DiffImageGenerator $diffImageGenerator;

    private readonly ContextArtifactGenerator $contextArtifactGenerator;

    public function compare(
        string $baselinePath,
        string $candidatePath,
        int $shiftTolerance = 0,
        int $colorThreshold = 0,
        ?string $artifactDirectory = null,
        int $pageHeight = 0,
        int $maxFullpageHeight = 0,
        int $contextPadding = 200
    ): ComparisonResult {
        $baselineImage = $this->loadImage($baselinePath);
        $candidateImage = $this->loadImage($candidatePath);

        $width = max($baselineImage->getImageWidth(), $candidateImage->getImageWidth());
        $height = max($baselineImage->getImageHeight(), $candidateImage->getImageHeight());

        $normalizedBaseline = $this->normalizeCanvas($baselineImage, $width, $height);
        $normalizedCandidate = $this->normalizeCanvas($candidateImage, $width, $height);
        $baselineImage->clear();
        $candidateImage->clear();

        $comparison = $shiftTolerance === 0
            ? $this->comparePixelsExactly($normalizedBaseline, $normalizedCandidate, $colorThreshold)
            : $this->comparePixelsWithTolerance($normalizedBaseline, $normalizedCandidate, $shiftTolerance, $colorThreshold);
        $changedPixels = $comparison['changedPixels'];
        $changedRuns = $comparison['changedRuns'];
        $pixelCount = $width * $height;
        $status = $changedPixels === 0 ? 'matching' : 'different';
        $artifacts = [];

        if ($artifactDirectory !== null && $changedPixels > 0) {
            $artifacts = $this->writeArtifacts(
                $artifactDirectory,
                $normalizedBaseline,
                $normalizedCandidate,
                $changedRuns,
                $pageHeight,
                $maxFullpageHeight,
                $contextPadding
            );
        }

        try {
            return new ComparisonResult(
                $status,
                $changedPixels > 0,
                $pixelCount,
                $changedPixels,
                $pixelCount > 0 ? $changedPixels / $pixelCount : 0.0,
                $artifacts
            );
        } finally {
            $normalizedBaseline->clear();
            $normalizedCandidate->clear();
            $changedRuns->cleanup();
        }
    }

    /**
     * @return array{changedPixels:int, changedRuns: ChangedRunStore}
     */
    private function comparePixelsExactly(
        Imagick $baselineImage,
        Imagick $candidateImage,
        int $colorThreshold
    ): array {
        if ($colorThreshold === 0 && $baselineImage->getImageSignature() === $candidateImage->getImageSignature()) {
            return [
                'changedPixels' => 0,
                'changedRuns' => new ChangedRunStore(),
            ];
        }

        $width = $baselineImage->getImageWidth();
        $height = $baselineImage->getImageHeight();
        $changedRuns = new ChangedRunStore();
        $changedPixels = 0;

        for ($y = 0; $y < $height; $y++) {
            $baselineRow = $this->exportRowPixels($baselineImage, $width, $y);
            $candidateRow = $this->exportRowPixels($candidateImage, $width, $y);
            $runStartX = null;

            for ($x = 0; $x < $width; $x++) {
                if ($this->pixelsMatchAtSamePosition($baselineRow, $candidateRow, $x, $colorThreshold)) {
                    if ($runStartX !== null) {
                        $changedRuns->add($y, $runStartX, $x - 1);
                        $runStartX = null;
                    }

                    continue;
                }

                $changedPixels++;
                $runStartX ??= $x;
            }

            if ($runStartX !== null) {
                $changedRuns->add($y, $runStartX, $width - 1);
            }
        }

        return [
            'changedPixels' => $changedPixels,
            'changedRuns' => $changedRuns,
        ];
    }

    private function comparePixelsWithTolerance(
        Imagick $baselineImage,
        Imagick $candidateImage,
        int $shiftTolerance,
        int $colorThreshold
    ): array {
        $width = $baselineImage->getImageWidth();
        $height = $baselineImage->getImageHeight();

        $matchedBaselinePixels = str_repeat("\0", intdiv(($width * $height) + 7, 8));
        $changedRuns = new ChangedRunStore();
        $changedPixels = 0;
        $baselineRowCache = [];

        for ($y = 0; $y < $height; $y++) {
            $candidateRow = $this->exportRowPixels($candidateImage, $width, $y);
            $baselineRow = $this->baselineRow($baselineImage, $baselineRowCache, $width, $y, $shiftTolerance);
            $runStartX = null;

            for ($x = 0; $x < $width; $x++) {
                $pixelIndex = ($y * $width) + $x;

                if ($this->pixelsMatchAtSamePosition($baselineRow, $candidateRow, $x, $colorThreshold)) {
                    if ($runStartX !== null) {
                        $changedRuns->add($y, $runStartX, $x - 1);
                        $runStartX = null;
                    }

                    continue;
                }

                $matchIndex = $this->findNearbyBaselineMatch(
                    $baselineImage,
                    $baselineRowCache,
                    $candidateRow,
                    $width,
                    $height,
                    $x,
                    $y,
                    $shiftTolerance,
                    $colorThreshold,
                    $matchedBaselinePixels
                );

                if ($matchIndex !== null) {
                    $this->markPixelAsUsed($matchedBaselinePixels, $matchIndex);

                    if ($runStartX !== null) {
                        $changedRuns->add($y, $runStartX, $x - 1);
                        $runStartX = null;
                    }

                    continue;
                }

                $changedPixels++;
                $runStartX ??= $x;
            }

            if ($runStartX !== null) {
                $changedRuns->add($y, $runStartX, $width - 1);
            }

            $this->pruneBaselineRowCache($baselineRowCache, $y, $shiftTolerance);
        }

        return [
            'changedPixels' => $changedPixels,
            'changedRuns' => $changedRuns,
        ];
    }

    private function loadImage(string $path): Imagick
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Image file not found: %s', $path));
        }

        $image = new Imagick();
        $image->readImage($path);
        $image->setImageColorspace(Imagick::COLORSPACE_RGB);

        return $image;
    }

    private function normalizeCanvas(Imagick $image, int $width, int $height): Imagick
    {
        $normalized = clone $image;
        $normalized->setImageBackgroundColor(new ImagickPixel('transparent'));
        $normalized->extentImage($width, $height, 0, 0);

        return $normalized;
    }

    /**
     * Compare a single pixel from the same x-position in two row buffers.
     *
     * Reading one row at a time keeps PHP memory bounded even for tall pages.
     *
     * @param array<int, int> $baselineRow
     * @param array<int, int> $candidateRow
     */
    private function pixelsMatchAtSamePosition(array $baselineRow, array $candidateRow, int $x, int $colorThreshold): bool
    {
        $offset = $x * 4;

        return abs($baselineRow[$offset] - $candidateRow[$offset]) <= $colorThreshold
            && abs($baselineRow[$offset + 1] - $candidateRow[$offset + 1]) <= $colorThreshold
            && abs($baselineRow[$offset + 2] - $candidateRow[$offset + 2]) <= $colorThreshold
            && abs($baselineRow[$offset + 3] - $candidateRow[$offset + 3]) <= $colorThreshold;
    }

    /**
     * Search for a nearby baseline pixel that matches the candidate pixel and
     * has not already been matched by a previous pixel.
     *
     * Instead of exporting the entire image, rows are loaded on demand and
     * cached only for the current tolerance window.
     *
     * @param array<int, array<int, int>> $baselineRowCache
     * @param array<int, int> $candidateRow
     */
    private function findNearbyBaselineMatch(
        Imagick $baselineImage,
        array &$baselineRowCache,
        array $candidateRow,
        int $width,
        int $height,
        int $candidateX,
        int $candidateY,
        int $shiftTolerance,
        int $colorThreshold,
        string $matchedBaselinePixels
    ): ?int {
        if ($shiftTolerance <= 0) {
            return null;
        }

        for ($distance = 1; $distance <= $shiftTolerance; $distance++) {
            for ($offsetY = -$distance; $offsetY <= $distance; $offsetY++) {
                for ($offsetX = -$distance; $offsetX <= $distance; $offsetX++) {
                    $baselineX = $candidateX + $offsetX;
                    $baselineY = $candidateY + $offsetY;

                    if ($baselineX < 0 || $baselineY < 0 || $baselineX >= $width || $baselineY >= $height) {
                        continue;
                    }

                    $baselineIndex = ($baselineY * $width) + $baselineX;

                    if ($this->isPixelUsed($matchedBaselinePixels, $baselineIndex)) {
                        continue;
                    }

                    $baselineRow = $this->baselineRow($baselineImage, $baselineRowCache, $width, $baselineY, $shiftTolerance);

                    if ($this->pixelsMatchAtIndices($baselineRow, $candidateRow, $baselineX, $candidateX, $colorThreshold)) {
                        return $baselineIndex;
                    }
                }
            }
        }

        return null;
    }

    private function isPixelUsed(string $matchedBaselinePixels, int $pixelIndex): bool
    {
        $byteIndex = intdiv($pixelIndex, 8);
        $bitMask = 1 << ($pixelIndex % 8);

        return (ord($matchedBaselinePixels[$byteIndex]) & $bitMask) !== 0;
    }

    private function markPixelAsUsed(string &$matchedBaselinePixels, int $pixelIndex): void
    {
        $byteIndex = intdiv($pixelIndex, 8);
        $bitMask = 1 << ($pixelIndex % 8);
        $current = ord($matchedBaselinePixels[$byteIndex]);
        $matchedBaselinePixels[$byteIndex] = chr($current | $bitMask);
    }

    /**
     * @param array<int, int> $baselineRow
     * @param array<int, int> $candidateRow
     */
    private function pixelsMatchAtIndices(array $baselineRow, array $candidateRow, int $baselineX, int $candidateX, int $colorThreshold): bool
    {
        $baselineOffset = $baselineX * 4;
        $candidateOffset = $candidateX * 4;

        return abs($baselineRow[$baselineOffset] - $candidateRow[$candidateOffset]) <= $colorThreshold
            && abs($baselineRow[$baselineOffset + 1] - $candidateRow[$candidateOffset + 1]) <= $colorThreshold
            && abs($baselineRow[$baselineOffset + 2] - $candidateRow[$candidateOffset + 2]) <= $colorThreshold
            && abs($baselineRow[$baselineOffset + 3] - $candidateRow[$candidateOffset + 3]) <= $colorThreshold;
    }

    /**
     * Export one row of RGBA pixels.
     *
     * Keeping the working set to one row at a time prevents the comparator from
     * allocating giant PHP arrays for tall full-page screenshots.
     *
     * @return array<int, int>
     */
    private function exportRowPixels(Imagick $image, int $width, int $y): array
    {
        return $image->exportImagePixels(0, $y, $width, 1, 'RGBA', Imagick::PIXEL_CHAR);
    }

    /**
     * Load a baseline row into a small sliding cache.
     *
     * @param array<int, array<int, int>> $baselineRowCache
     * @return array<int, int>
     */
    private function baselineRow(Imagick $baselineImage, array &$baselineRowCache, int $width, int $y, int $shiftTolerance): array
    {
        if (!array_key_exists($y, $baselineRowCache)) {
            $baselineRowCache[$y] = $this->exportRowPixels($baselineImage, $width, $y);
        }

        if ($shiftTolerance >= 0) {
            $this->pruneBaselineRowCache($baselineRowCache, $y, $shiftTolerance);
        }

        return $baselineRowCache[$y];
    }

    /**
     * Drop rows that can no longer participate in nearby-pixel matching.
     *
     * @param array<int, array<int, int>> $baselineRowCache
     */
    private function pruneBaselineRowCache(array &$baselineRowCache, int $currentY, int $shiftTolerance): void
    {
        $minimumY = $currentY - max($shiftTolerance, 0);

        foreach (array_keys($baselineRowCache) as $cachedY) {
            if ($cachedY < $minimumY) {
                unset($baselineRowCache[$cachedY]);
            }
        }
    }

    /**
     * @return array<string, ?string>
     */
    private function writeArtifacts(
        string $artifactDirectory,
        Imagick $baselineImage,
        Imagick $candidateImage,
        ChangedRunStore $changedRuns,
        int $pageHeight,
        int $maxFullpageHeight,
        int $contextPadding
    ): array {
        if (!is_dir($artifactDirectory) && !mkdir($artifactDirectory, 0777, true) && !is_dir($artifactDirectory)) {
            throw new RuntimeException(sprintf('Could not create artifact directory: %s', $artifactDirectory));
        }

        $baselinePath = $this->artifactImageWriter->artifactPath($artifactDirectory, 'baseline');
        $candidatePath = $this->artifactImageWriter->artifactPath($artifactDirectory, 'candidate');
        $overlayPath = $this->artifactImageWriter->artifactPath($artifactDirectory, 'overlay');
        $diffPath = $this->artifactImageWriter->artifactPath($artifactDirectory, 'diff');

        $baselineImage = clone $baselineImage;
        $candidateImage = clone $candidateImage;
        try {
            $this->artifactImageWriter->writeImage($baselineImage, $baselinePath);
            $this->artifactImageWriter->writeImage($candidateImage, $candidatePath);

            $this->overlayGenerator->generate($baselineImage, $candidateImage, $overlayPath);
            $this->diffImageGenerator->generate($baselineImage, $changedRuns, $diffPath);

            $contextPath = null;

            if ($pageHeight > 0 && $maxFullpageHeight > 0 && $pageHeight > $maxFullpageHeight) {
                $bounds = $changedRuns->bounds();

                if ($bounds !== null) {
                    $contextPath = $this->artifactImageWriter->artifactPath($artifactDirectory, 'context');
                    $this->contextArtifactGenerator->generate(
                        $baselineImage,
                        $candidateImage,
                        $bounds['minY'],
                        $bounds['maxY'],
                        $contextPadding,
                        $contextPath
                    );
                }
            }

            return [
                'baseline' => $baselinePath,
                'candidate' => $candidatePath,
                'overlay' => $overlayPath,
                'diff' => $diffPath,
                'context' => $contextPath,
            ];
        } finally {
            $baselineImage->clear();
            $candidateImage->clear();
        }
    }
}
