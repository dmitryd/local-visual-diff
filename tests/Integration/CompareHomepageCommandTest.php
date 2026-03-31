<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Integration;

use LocalVisualDiff\ApplicationFactory;
use PHPUnit\Framework\TestCase;

final class CompareHomepageCommandTest extends TestCase
{
    public function testCompareCommandIsRegisteredWithPositionalUrls(): void
    {
        $application = ApplicationFactory::create();
        $command = $application->find('compare');

        self::assertSame('compare', $command->getName());

        $arguments = $command->getDefinition()->getArguments();
        self::assertArrayHasKey('baseline-base-url', $arguments);
        self::assertArrayHasKey('candidate-base-url', $arguments);
        self::assertCount(2, $arguments);
    }
}
