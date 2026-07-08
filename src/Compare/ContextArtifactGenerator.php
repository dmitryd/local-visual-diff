<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use Imagick;

/**
 * Write a cropped overlay artifact for tall pages.
 *
 * Task 4 does not use this generator yet, but it is implemented now so later
 * fallback work can reuse the same visual language as the main overlay image.
 */
final class ContextArtifactGenerator
{
    public function __construct(private readonly OverlayGenerator $overlayGenerator = new OverlayGenerator())
    {
    }

    public function generate(
        Imagick $baselineImage,
        Imagick $candidateImage,
        int $startY,
        int $endY,
        int $padding,
        string $outputPath
    ): string {
        $this->ensureDirectoryExists($outputPath);

        $baseline = clone $baselineImage;
        $candidate = clone $candidateImage;

        $width = max($baseline->getImageWidth(), $candidate->getImageWidth());
        $height = max($baseline->getImageHeight(), $candidate->getImageHeight());
        $cropTop = max(0, $startY - $padding);
        $cropBottom = min($height, $endY + $padding + 1);
        $cropHeight = max(1, $cropBottom - $cropTop);

        $baseline->extentImage($width, $height, 0, 0);
        $candidate->extentImage($width, $height, 0, 0);

        $baseline->cropImage($width, $cropHeight, 0, $cropTop);
        $baseline->setImagePage(0, 0, 0, 0);

        $candidate->cropImage($width, $cropHeight, 0, $cropTop);
        $candidate->setImagePage(0, 0, 0, 0);

        try {
            return $this->overlayGenerator->generate($baseline, $candidate, $outputPath);
        } finally {
            $baseline->clear();
            $candidate->clear();
        }
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
