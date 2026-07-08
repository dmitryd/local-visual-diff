<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Compare;

use Imagick;
use ImagickPixel;
use LocalVisualDiff\Compare\ImageComparisonFailedException;
use LocalVisualDiff\Compare\SubprocessImageComparator;
use PHPUnit\Framework\TestCase;

final class SubprocessImageComparatorTest extends TestCase
{
    public function testWorkerSuccessReturnsComparisonResult(): void
    {
        $workdir = $this->createWorkdir('success');
        $baseline = $workdir . '/baseline.png';
        $candidate = $workdir . '/candidate.png';

        $this->createImage($baseline, 'white');
        $this->createImage($candidate, 'black');

        $result = (new SubprocessImageComparator('png', '128M'))->compare(
            $baseline,
            $candidate,
            artifactDirectory: $workdir . '/artifacts'
        );

        self::assertSame('different', $result->status);
        self::assertTrue($result->hasDiff);
        self::assertGreaterThan(0, $result->changedPixelCount);
        self::assertFileExists($result->artifacts['diff']);
    }

    public function testWorkerFailureIsConvertedToControlledException(): void
    {
        $workdir = $this->createWorkdir('failure');
        $worker = $this->createWorker($workdir, "fwrite(STDERR, 'worker exploded'); exit(9);");

        $this->expectException(ImageComparisonFailedException::class);
        $this->expectExceptionMessage('worker exploded');

        (new SubprocessImageComparator('png', '128M', $worker))->compare('missing-a.png', 'missing-b.png');
    }

    public function testWorkerMemoryFailureIsReportedAsMemoryExhausted(): void
    {
        $workdir = $this->createWorkdir('oom');
        $worker = $this->createWorker(
            $workdir,
            "fwrite(STDERR, 'Fatal error: Allowed memory size of 134217728 bytes exhausted'); exit(255);"
        );

        $this->expectException(ImageComparisonFailedException::class);
        $this->expectExceptionMessage('memory exhausted');

        (new SubprocessImageComparator('png', '128M', $worker))->compare('missing-a.png', 'missing-b.png');
    }

    private function createWorkdir(string $name): string
    {
        $directory = sys_get_temp_dir() . '/local-visual-diff-subprocess-' . $name . '-' . bin2hex(random_bytes(4));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(sprintf('Could not create test directory: %s', $directory));
        }

        return $directory;
    }

    private function createImage(string $path, string $color): void
    {
        $image = new Imagick();
        $image->newImage(20, 20, new ImagickPixel($color), 'png');
        $image->setImageFormat('png');
        $image->writeImage($path);
        $image->clear();
    }

    private function createWorker(string $directory, string $body): string
    {
        $path = $directory . '/worker.php';
        file_put_contents($path, "#!/usr/bin/env php\n<?php\n" . $body . "\n");

        return $path;
    }
}
