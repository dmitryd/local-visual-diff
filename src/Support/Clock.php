<?php

declare(strict_types=1);

namespace LocalVisualDiff\Support;

/**
 * Provide run timestamps from one place so tests can replace the clock with a
 * deterministic subclass.
 */
class Clock
{
    public function timestampForRunDirectory(): string
    {
        return date('Ymd-His');
    }

    public function formattedNow(): string
    {
        return date('Y-m-d H:i:s');
    }
}
