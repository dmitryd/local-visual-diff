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
     * @param array<int, array{y:int,startX:int,endX:int}> $changedRuns
     */
    public function generate(Imagick $baselineImage, array $changedRuns, string $outputPath): string
    {
        $this->ensureDirectoryExists($outputPath);

        $image = clone $baselineImage;
        $image->modulateImage(65, 0, 100);

        $highlight = new ImagickPixel('rgba(255,0,0,1)');
        $draw = new ImagickDraw();
        $draw->setFillColor($highlight);

        foreach ($changedRuns as $run) {
            $draw->line($run['startX'], $run['y'], $run['endX'], $run['y']);
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
