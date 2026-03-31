<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Integration;

use LocalVisualDiff\Command\CompareCommand;
use LocalVisualDiff\Support\Clock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercise the real command with local fixture pages so the end-to-end wiring
 * covers browser capture, comparison, output directory creation, and report
 * generation without requiring a network connection.
 */
final class CompareHomepageCommandTest extends TestCase
{
    public function testItCreatesATimestampedReportDirectoryForHomepageComparison(): void
    {
        $fixtureRoot = $this->createFixtureDirectory();
        $baselinePath = $fixtureRoot . DIRECTORY_SEPARATOR . 'baseline.html';
        $candidatePath = $fixtureRoot . DIRECTORY_SEPARATOR . 'candidate.html';
        $outputDir = dirname(__DIR__, 2) . '/var/results/run-20260331-103000';

        $this->writeFixtureFile($baselinePath);
        $this->writeFixtureFile($candidatePath);
        $this->removeDirectoryIfExists($outputDir);

        try {
            $command = new CompareCommand(clock: new class () extends Clock {
                public function timestampForRunDirectory(): string
                {
                    return '20260331-103000';
                }

                public function formattedNow(): string
                {
                    return '2026-03-31 10:30:00';
                }
            });

            $tester = new CommandTester($command);
            $tester->execute([
                'baseline-base-url' => 'file://' . $baselinePath,
                'candidate-base-url' => 'file://' . $candidatePath,
            ]);

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertFileExists($outputDir . DIRECTORY_SEPARATOR . 'report.json');

            $html = file_get_contents($outputDir . DIRECTORY_SEPARATOR . 'report.html');
            self::assertIsString($html);
            self::assertStringContainsString('Local Visual Diff Report', $html);
            self::assertStringContainsString('matching', $html);
            self::assertStringContainsString('Baseline', $html);
            self::assertStringContainsString('Candidate', $html);
            self::assertStringContainsString('Baseline shot', $html);
            self::assertStringContainsString('Candidate shot', $html);
        } finally {
            $this->removeDirectoryIfExists($fixtureRoot);
            $this->removeDirectoryIfExists($outputDir);
        }
    }

    private function createFixtureDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local-visual-diff-' . bin2hex(random_bytes(4));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(sprintf('Could not create fixture directory: %s', $directory));
        }

        return $directory;
    }

    private function writeFixtureFile(string $path): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Home</title>
</head>
<body>
    <main>
        <h1>Visual Diff Fixture</h1>
        <p>This content is intentionally stable so the screenshot comparison can
        exercise the matching-path code path.</p>
    </main>
</body>
</html>
HTML;

        if (file_put_contents($path, $html) === false) {
            self::fail(sprintf('Could not write fixture file: %s', $path));
        }
    }

    private function removeDirectoryIfExists(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
