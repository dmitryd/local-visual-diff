<?php

declare(strict_types=1);

namespace LocalVisualDiff;

use LocalVisualDiff\Command\CompareImagePairCommand;
use LocalVisualDiff\Command\CompareCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    /**
     * Build the console application for the tool.
     *
     * The public entrypoint stays in single-command mode, while the worker
     * entrypoint can address hidden internal commands by name.
     */
    public static function create(bool $singleCommand = true): Application
    {
        $application = new Application('local-visual-diff');
        $application->add(new CompareCommand());
        $application->add(new CompareImagePairCommand());
        $application->setDefaultCommand('compare', $singleCommand);

        return $application;
    }
}
