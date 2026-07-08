<?php

declare(strict_types=1);

namespace LocalVisualDiff\Command;

use LocalVisualDiff\Compare\ImageComparator;
use LocalVisualDiff\Support\ArtifactImageWriter;
use LocalVisualDiff\Support\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This class contains the internal image comparison worker command.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 */
final class CompareImagePairCommand extends Command
{
    public function __construct(
        protected readonly Filesystem $filesystem = new Filesystem()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('compare-image-pair')
            ->setDescription('Internal worker: compare two captured screenshots.')
            ->setHidden(true)
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Baseline screenshot path.')
            ->addOption('candidate', null, InputOption::VALUE_REQUIRED, 'Candidate screenshot path.')
            ->addOption('shift-tolerance', null, InputOption::VALUE_REQUIRED, 'Pixel shift tolerance.', '0')
            ->addOption('color-threshold', null, InputOption::VALUE_REQUIRED, 'Color threshold.', '0')
            ->addOption('artifact-directory', null, InputOption::VALUE_REQUIRED, 'Artifact directory.')
            ->addOption('page-height', null, InputOption::VALUE_REQUIRED, 'Captured page height.', '0')
            ->addOption('max-fullpage-height', null, InputOption::VALUE_REQUIRED, 'Maximum full-page height.', '0')
            ->addOption('context-padding', null, InputOption::VALUE_REQUIRED, 'Context artifact padding.', '200')
            ->addOption('artifact-image-format', null, InputOption::VALUE_REQUIRED, 'Artifact image format.', 'webp')
            ->addOption('result-json', null, InputOption::VALUE_REQUIRED, 'Result JSON output path.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baselinePath = $this->requiredStringOption($input, 'baseline');
        $candidatePath = $this->requiredStringOption($input, 'candidate');
        $resultPath = $this->requiredStringOption($input, 'result-json');
        $artifactDirectory = $this->optionalStringOption($input, 'artifact-directory');
        $artifactImageFormat = $this->requiredStringOption($input, 'artifact-image-format');

        $comparator = new ImageComparator(new ArtifactImageWriter($artifactImageFormat));
        $result = $comparator->compare(
            $baselinePath,
            $candidatePath,
            (int) $input->getOption('shift-tolerance'),
            (int) $input->getOption('color-threshold'),
            $artifactDirectory,
            (int) $input->getOption('page-height'),
            (int) $input->getOption('max-fullpage-height'),
            (int) $input->getOption('context-padding')
        );

        $this->filesystem->writeJson($resultPath, [
            'status' => $result->status,
            'hasDiff' => $result->hasDiff,
            'pixelCount' => $result->pixelCount,
            'changedPixelCount' => $result->changedPixelCount,
            'diffRatio' => $result->diffRatio,
            'artifacts' => $result->artifacts,
        ]);

        return Command::SUCCESS;
    }

    protected function requiredStringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }

    protected function optionalStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('The --%s option must be a string.', $name));
        }

        return $value;
    }
}
