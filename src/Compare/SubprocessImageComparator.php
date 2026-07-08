<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * This class contains a subprocess-backed screenshot comparator.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
final class SubprocessImageComparator implements ImageComparatorInterface
{
    protected const DEFAULT_TIMEOUT_SECONDS = 300;

    public function __construct(
        protected readonly string $artifactImageFormat = 'webp',
        protected readonly string $memoryLimit = '512M',
        protected readonly ?string $workerEntrypoint = null,
        protected readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
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
        $resultPath = $this->temporaryResultPath();
        $process = $this->createProcess(
            $baselinePath,
            $candidatePath,
            $shiftTolerance,
            $colorThreshold,
            $artifactDirectory,
            $pageHeight,
            $maxFullpageHeight,
            $contextPadding,
            $resultPath
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw new ImageComparisonFailedException('image comparison failed in worker: timed out', 0, $exception);
        }

        try {
            if (!$process->isSuccessful()) {
                throw new ImageComparisonFailedException(sprintf(
                    'image comparison failed in worker: %s',
                    $this->failureReason($process)
                ));
            }

            return $this->readResult($resultPath);
        } finally {
            if (is_file($resultPath)) {
                @unlink($resultPath);
            }
        }
    }

    protected function createProcess(
        string $baselinePath,
        string $candidatePath,
        int $shiftTolerance,
        int $colorThreshold,
        ?string $artifactDirectory,
        int $pageHeight,
        int $maxFullpageHeight,
        int $contextPadding,
        string $resultPath
    ): Process {
        $command = [
            PHP_BINARY,
            '-d',
            'memory_limit=' . $this->memoryLimit,
            $this->workerEntrypoint ?? dirname(__DIR__, 2) . '/bin/visual-diff-worker',
            'compare-image-pair',
            '--baseline',
            $baselinePath,
            '--candidate',
            $candidatePath,
            '--shift-tolerance',
            (string) $shiftTolerance,
            '--color-threshold',
            (string) $colorThreshold,
            '--page-height',
            (string) $pageHeight,
            '--max-fullpage-height',
            (string) $maxFullpageHeight,
            '--context-padding',
            (string) $contextPadding,
            '--artifact-image-format',
            $this->artifactImageFormat,
            '--result-json',
            $resultPath,
        ];

        if ($artifactDirectory !== null) {
            $command[] = '--artifact-directory';
            $command[] = $artifactDirectory;
        }

        $process = new Process($command, dirname(__DIR__, 2));
        $process->setTimeout($this->timeoutSeconds);

        return $process;
    }

    protected function temporaryResultPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lvd-compare-result-');

        if ($path === false) {
            throw new ImageComparisonFailedException('image comparison failed in worker: could not create result file');
        }

        return $path;
    }

    protected function failureReason(Process $process): string
    {
        $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());

        if (stripos($output, 'Allowed memory size') !== false || stripos($output, 'out of memory') !== false) {
            return 'memory exhausted';
        }

        if ($output !== '') {
            return preg_replace('/\s+/', ' ', $output) ?? $output;
        }

        return sprintf('exit code %s', (string) $process->getExitCode());
    }

    protected function readResult(string $resultPath): ComparisonResult
    {
        if (!is_file($resultPath)) {
            throw new ImageComparisonFailedException('image comparison failed in worker: result file was not written');
        }

        try {
            $data = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ImageComparisonFailedException('image comparison failed in worker: invalid result JSON', 0, $exception);
        }

        if (!is_array($data)) {
            throw new ImageComparisonFailedException('image comparison failed in worker: invalid result payload');
        }

        return new ComparisonResult(
            (string) ($data['status'] ?? 'runtime_error'),
            (bool) ($data['hasDiff'] ?? false),
            (int) ($data['pixelCount'] ?? 0),
            (int) ($data['changedPixelCount'] ?? 0),
            (float) ($data['diffRatio'] ?? 0.0),
            is_array($data['artifacts'] ?? null) ? $data['artifacts'] : []
        );
    }
}
