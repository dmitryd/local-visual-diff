<?php

declare(strict_types=1);

namespace LocalVisualDiff\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

final class CompareCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('compare')
            ->setDescription('Compare a baseline website against a candidate website visually.')
            ->addArgument(
                'baseline-base-url',
                InputArgument::REQUIRED,
                'The baseline site URL that acts as the reference for comparison.'
            )
            ->addArgument(
                'candidate-base-url',
                InputArgument::REQUIRED,
                'The candidate site URL that should match the baseline visually.'
            );
    }
}
