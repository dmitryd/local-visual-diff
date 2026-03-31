<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use Imagick;
use ImagickPixel;

/**
 * Write a human-friendly overlay image.
 *
 * The overlay places the candidate screenshot above the baseline screenshot
 * with partial opacity so even small shifts become visually obvious.
 */
final class OverlayGenerator
{
    public function generate(Imagick $baselineImage, Imagick $candidateImage, string $outputPath): string
    {
        $this->ensureDirectoryExists($outputPath);

        $baseline = clone $baselineImage;
        $candidate = clone $candidateImage;

        $width = max($baseline->getImageWidth(), $candidate->getImageWidth());
        $height = max($baseline->getImageHeight(), $candidate->getImageHeight());

        $baseline = $this->normalizeCanvas($baseline, $width, $height);
        $candidate = $this->normalizeCanvas($candidate, $width, $height);

        $candidate->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        $candidate->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.5, Imagick::CHANNEL_ALPHA);

        $baseline->compositeImage($candidate, Imagick::COMPOSITE_OVER, 0, 0);
        $baseline->setImageFormat('png');
        $baseline->writeImage($outputPath);

        return $outputPath;
    }

    private function normalizeCanvas(Imagick $image, int $width, int $height): Imagick
    {
        $image->setImageBackgroundColor(new ImagickPixel('transparent'));
        $image->extentImage($width, $height, 0, 0);

        return $image;
    }

    private function ensureDirectoryExists(string $outputPath): void
    {
        $directory = dirname($outputPath);

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create output directory: %s', $directory));
        }
    }
}
