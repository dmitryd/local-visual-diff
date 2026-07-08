<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Compare;

use LocalVisualDiff\Compare\ChangedRunStore;
use PHPUnit\Framework\TestCase;

final class ChangedRunStoreTest extends TestCase
{
    public function testEmptyStoreHasNoBoundsAndNoRuns(): void
    {
        $store = new ChangedRunStore();

        self::assertSame(0, $store->count());
        self::assertNull($store->bounds());
        self::assertSame([], iterator_to_array($store));
    }

    public function testRunsAreStoredInOrderAndBoundsAreTracked(): void
    {
        $store = new ChangedRunStore();
        $store->add(10, 3, 8);
        $store->add(4, 1, 2);
        $store->add(15, 6, 9);

        self::assertSame(3, $store->count());
        self::assertSame(['minY' => 4, 'maxY' => 15], $store->bounds());
        self::assertSame(
            [
                ['y' => 10, 'startX' => 3, 'endX' => 8],
                ['y' => 4, 'startX' => 1, 'endX' => 2],
                ['y' => 15, 'startX' => 6, 'endX' => 9],
            ],
            iterator_to_array($store)
        );
    }

    public function testCleanupRemovesBackingFile(): void
    {
        $store = new ChangedRunStore();
        $path = $store->path();

        self::assertFileExists($path);

        $store->cleanup();

        self::assertFileDoesNotExist($path);
    }
}
