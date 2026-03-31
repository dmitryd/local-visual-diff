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

        $baselinePixels = $baselineImage->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);
        $candidatePixels = $candidateImage->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);

        $matchedBaselinePixels = str_repeat("\0", intdiv(($width * $height) + 7, 8));
        $changedCoordinates = [];
        $changedPixels = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixelIndex = ($y * $width) + $x;

                if ($this->pixelsMatchAtSamePosition($baselinePixels, $candidatePixels, $pixelIndex, $colorThreshold)) {
                    continue;
                }

                $matchIndex = $this->findNearbyBaselineMatch(
                    $baselinePixels,
                    $candidatePixels,
                    $width,
                    $height,
                    $pixelIndex,
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
     * @param array<int, int> $baselinePixels
     * @param array<int, int> $candidatePixels
     */
    private function pixelsMatchAtSamePosition(array $baselinePixels, array $candidatePixels, int $pixelIndex, int $colorThreshold): bool
    {
        $offset = $pixelIndex * 4;

        return abs($baselinePixels[$offset] - $candidatePixels[$offset]) <= $colorThreshold
            && abs($baselinePixels[$offset + 1] - $candidatePixels[$offset + 1]) <= $colorThreshold
            && abs($baselinePixels[$offset + 2] - $candidatePixels[$offset + 2]) <= $colorThreshold
            && abs($baselinePixels[$offset + 3] - $candidatePixels[$offset + 3]) <= $colorThreshold;
    }

    /**
     * Search for a nearby baseline pixel that matches the candidate pixel and
     * has not already been matched by a previous pixel.
     *
     * @param array<int, int> $baselinePixels
     * @param array<int, int> $candidatePixels
     */
    private function findNearbyBaselineMatch(
        array $baselinePixels,
        array $candidatePixels,
        int $width,
        int $height,
        int $candidateIndex,
        int $shiftTolerance,
        int $colorThreshold,
        string $matchedBaselinePixels
    ): ?int {
        if ($shiftTolerance <= 0) {
            return null;
        }

        $candidateX = $candidateIndex % $width;
        $candidateY = intdiv($candidateIndex, $width);

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

                    if ($this->pixelsMatchAtIndices($baselinePixels, $candidatePixels, $baselineIndex, $candidateIndex, $colorThreshold)) {
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
     * @param array<int, int> $baselinePixels
     * @param array<int, int> $candidatePixels
     */
    private function pixelsMatchAtIndices(array $baselinePixels, array $candidatePixels, int $baselineIndex, int $candidateIndex, int $colorThreshold): bool
    {
        $baselineOffset = $baselineIndex * 4;
        $candidateOffset = $candidateIndex * 4;

        return abs($baselinePixels[$baselineOffset] - $candidatePixels[$candidateOffset]) <= $colorThreshold
            && abs($baselinePixels[$baselineOffset + 1] - $candidatePixels[$candidateOffset + 1]) <= $colorThreshold
            && abs($baselinePixels[$baselineOffset + 2] - $candidatePixels[$candidateOffset + 2]) <= $colorThreshold
            && abs($baselinePixels[$baselineOffset + 3] - $candidatePixels[$candidateOffset + 3]) <= $colorThreshold;
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
