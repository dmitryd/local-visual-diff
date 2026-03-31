<?php

declare(strict_types=1);

namespace LocalVisualDiff;

use LocalVisualDiff\Command\CompareCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    /**
     * Build the console application for the tool.
     *
     * The application currently exposes a single default command because the
     * first implementation milestone only needs one workflow: compare a
     * baseline site against a candidate site.
     */
    public static function create(): Application
    {
        $application = new Application('local-visual-diff');
        $application->add(new CompareCommand());
        $application->setDefaultCommand('compare', true);

        return $application;
    }
}
