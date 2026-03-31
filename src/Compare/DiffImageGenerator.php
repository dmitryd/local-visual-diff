<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Write a compact diff image that keeps the baseline context visible while
 * highlighting changed pixels in red.
 */
final class DiffImageGenerator
{
    /**
     * @param array<int, array{0:int, 1:int}> $changedPixels
     */
    public function generate(Imagick $baselineImage, array $changedPixels, string $outputPath): string
    {
        $this->ensureDirectoryExists($outputPath);

        $image = clone $baselineImage;
        $image->modulateImage(65, 0, 100);

        $highlight = new ImagickPixel('rgba(255,0,0,1)');
        $draw = new ImagickDraw();
        $draw->setFillColor($highlight);

        foreach ($changedPixels as [$x, $y]) {
            $draw->point($x, $y);
        }

        $image->drawImage($draw);

        $image->setImageFormat('png');
        $image->writeImage($outputPath);

        return $outputPath;
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
