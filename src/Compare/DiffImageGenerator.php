<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use LocalVisualDiff\Support\ArtifactImageWriter;

/**
 * Write a compact diff image that keeps the baseline context visible while
 * highlighting changed pixels in red.
 */
final class DiffImageGenerator
{
    private const DRAW_CHUNK_SIZE = 10000;

    public function __construct(private readonly ArtifactImageWriter $artifactImageWriter = new ArtifactImageWriter())
    {
    }

    /**
     * @param iterable<int, array{y:int,startX:int,endX:int}> $changedRuns
     */
    public function generate(Imagick $baselineImage, iterable $changedRuns, string $outputPath): string
    {
        $image = clone $baselineImage;
        $image->modulateImage(65, 0, 100);

        $highlight = new ImagickPixel('rgba(255,0,0,1)');
        $draw = new ImagickDraw();
        $draw->setFillColor($highlight);
        $drawnRuns = 0;

        foreach ($changedRuns as $run) {
            $draw->line($run['startX'], $run['y'], $run['endX'], $run['y']);
            $drawnRuns++;

            if ($drawnRuns % self::DRAW_CHUNK_SIZE === 0) {
                $image->drawImage($draw);
                $draw->clear();
                $draw = new ImagickDraw();
                $draw->setFillColor($highlight);
            }
        }

        if ($drawnRuns % self::DRAW_CHUNK_SIZE !== 0) {
            $image->drawImage($draw);
        }

        $draw->clear();

        try {
            return $this->artifactImageWriter->writeImage($image, $outputPath);
        } finally {
            $image->clear();
        }
    }
}
