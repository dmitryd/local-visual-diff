<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use Imagick;
use ImagickPixel;
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
{
    public function __construct(
        private readonly OverlayGenerator $overlayGenerator = new OverlayGenerator(),
        private readonly DiffImageGenerator $diffImageGenerator = new DiffImageGenerator(),
        private readonly ContextArtifactGenerator $contextArtifactGenerator = new ContextArtifactGenerator()
    ) {
    }

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

        $comparison = $this->comparePixels($normalizedBaseline, $normalizedCandidate, $shiftTolerance, $colorThreshold);
        $changedPixels = $comparison['changedPixels'];
        $changedCoordinates = $comparison['changedCoordinates'];
        $pixelCount = $width * $height;
        $status = $changedPixels === 0 ? 'matching' : 'different';
        $artifacts = [];

        if ($artifactDirectory !== null && $changedPixels > 0) {
            $artifacts = $this->writeArtifacts(
                $artifactDirectory,
                $normalizedBaseline,
                $normalizedCandidate,
                $changedCoordinates,
                $pageHeight,
                $maxFullpageHeight,
                $contextPadding
            );
        }

        return new ComparisonResult(
            $status,
            $changedPixels > 0,
            $pixelCount,
            $changedPixels,
            $pixelCount > 0 ? $changedPixels / $pixelCount : 0.0,
            $artifacts
        );
    }

    /**
     * @return array{changedPixels:int, changedCoordinates: array<int, array{0:int,1:int}>}
     */
    private function comparePixels(
        Imagick $baselineImage,
        Imagick $candidateImage,
        int $shiftTolerance,
        int $colorThreshold
    ): array {
        $width = $baselineImage->getImageWidth();
        $height = $baselineImage->getImageHeight();

        $matchedBaselinePixels = str_repeat("\0", intdiv(($width * $height) + 7, 8));
        $changedCoordinates = [];
        $changedPixels = 0;
        $baselineRowCache = [];

        for ($y = 0; $y < $height; $y++) {
            $candidateRow = $this->exportRowPixels($candidateImage, $width, $y);
            $baselineRow = $this->baselineRow($baselineImage, $baselineRowCache, $width, $y, $shiftTolerance);

            for ($x = 0; $x < $width; $x++) {
                $pixelIndex = ($y * $width) + $x;

                if ($this->pixelsMatchAtSamePosition($baselineRow, $candidateRow, $x, $colorThreshold)) {
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
                    continue;
                }

                $changedPixels++;
                $changedCoordinates[] = [$x, $y];
            }

            $this->pruneBaselineRowCache($baselineRowCache, $y, $shiftTolerance);
        }

        return [
            'changedPixels' => $changedPixels,
            'changedCoordinates' => $changedCoordinates,
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
     * @param array<int, array{0:int,1:int}> $changedCoordinates
     * @return array<string, ?string>
     */
    private function writeArtifacts(
        string $artifactDirectory,
        Imagick $baselineImage,
        Imagick $candidateImage,
        array $changedCoordinates,
        int $pageHeight,
        int $maxFullpageHeight,
        int $contextPadding
    ): array {
        if (!is_dir($artifactDirectory) && !mkdir($artifactDirectory, 0777, true) && !is_dir($artifactDirectory)) {
            throw new RuntimeException(sprintf('Could not create artifact directory: %s', $artifactDirectory));
        }

        $baselinePath = $artifactDirectory . DIRECTORY_SEPARATOR . 'baseline.png';
        $candidatePath = $artifactDirectory . DIRECTORY_SEPARATOR . 'candidate.png';
        $overlayPath = $artifactDirectory . DIRECTORY_SEPARATOR . 'overlay.png';
        $diffPath = $artifactDirectory . DIRECTORY_SEPARATOR . 'diff.png';

        $baselineImage = clone $baselineImage;
        $candidateImage = clone $candidateImage;
        $baselineImage->setImageFormat('png');
        $candidateImage->setImageFormat('png');
        $baselineImage->writeImage($baselinePath);
        $candidateImage->writeImage($candidatePath);

        $this->overlayGenerator->generate($baselineImage, $candidateImage, $overlayPath);
        $this->diffImageGenerator->generate($baselineImage, $changedCoordinates, $diffPath);

        $contextPath = null;

        if ($pageHeight > 0 && $maxFullpageHeight > 0 && $pageHeight > $maxFullpageHeight) {
            $bounds = $this->changedCoordinateBounds($changedCoordinates);

            if ($bounds !== null) {
                $contextPath = $artifactDirectory . DIRECTORY_SEPARATOR . 'context.png';
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
    }

    /**
     * @param array<int, array{0:int,1:int}> $changedCoordinates
     * @return array{minY:int,maxY:int}|null
     */
    private function changedCoordinateBounds(array $changedCoordinates): ?array
    {
        if ($changedCoordinates === []) {
            return null;
        }

        $minY = $changedCoordinates[0][1];
        $maxY = $changedCoordinates[0][1];

        foreach ($changedCoordinates as [, $y]) {
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }

        return [
            'minY' => $minY,
            'maxY' => $maxY,
        ];
    }
}
