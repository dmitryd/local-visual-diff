<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Compare;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use LocalVisualDiff\Compare\ImageComparator;
use PHPUnit\Framework\TestCase;

final class ImageComparatorTest extends TestCase
{
    public function testIdenticalImagesAreMatching(): void
    {
        $workdir = $this->createWorkdir('identical');
        $baseline = $workdir . '/baseline.png';
        $candidate = $workdir . '/candidate.png';

        $this->createSceneImage($baseline, 20, 20, 3, 3, 8, 8, 'black');
        $this->createSceneImage($candidate, 20, 20, 3, 3, 8, 8, 'black');

        $result = (new ImageComparator())->compare($baseline, $candidate, 0, 0, $workdir . '/artifacts');

        self::assertSame('matching', $result->status);
        self::assertFalse($result->hasDiff);
        self::assertSame(0, $result->changedPixelCount);
        self::assertSame([], $result->artifacts);
    }

    public function testSmallShiftCanBeTolerated(): void
    {
        $workdir = $this->createWorkdir('shift');
        $baseline = $workdir . '/baseline.png';
        $candidate = $workdir . '/candidate.png';

        $this->createSceneImage($baseline, 20, 20, 3, 3, 8, 8, 'black');
        $this->createSceneImage($candidate, 20, 20, 5, 3, 10, 8, 'black');

        $result = (new ImageComparator())->compare($baseline, $candidate, 3, 0, $workdir . '/artifacts');

        self::assertSame('matching', $result->status);
        self::assertFalse($result->hasDiff);
        self::assertSame(0, $result->changedPixelCount);
    }

    public function testDifferentImagesWriteArtifacts(): void
    {
        $workdir = $this->createWorkdir('different');
        $baseline = $workdir . '/baseline.png';
        $candidate = $workdir . '/candidate.png';

        $this->createSceneImage($baseline, 20, 20, 3, 3, 8, 8, 'black');
        $this->createSceneImage($candidate, 20, 20, 3, 3, 8, 8, 'red');

        $result = (new ImageComparator())->compare($baseline, $candidate, 0, 0, $workdir . '/artifacts');

        self::assertSame('different', $result->status);
        self::assertTrue($result->hasDiff);
        self::assertGreaterThan(0, $result->changedPixelCount);
        self::assertFileExists($result->artifacts['baseline']);
        self::assertFileExists($result->artifacts['candidate']);
        self::assertFileExists($result->artifacts['overlay']);
        self::assertFileExists($result->artifacts['diff']);
    }

    private function createWorkdir(string $name): string
    {
        $directory = sys_get_temp_dir() . '/local-visual-diff-' . $name . '-' . bin2hex(random_bytes(4));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(sprintf('Could not create test directory: %s', $directory));
        }

        return $directory;
    }

    private function createSceneImage(
        string $path,
        int $width,
        int $height,
        int $x1,
        int $y1,
        int $x2,
        int $y2,
        string $fillColor
    ): void {
        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel('white'), 'png');
        $image->setImageFormat('png');

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel($fillColor));
        $draw->setStrokeColor(new ImagickPixel($fillColor));
        $draw->setStrokeWidth(1);
        $draw->setStrokeAntialias(false);
        $draw->rectangle($x1, $y1, $x2, $y2);

        $image->drawImage($draw);
        $image->writeImage($path);
    }
}
