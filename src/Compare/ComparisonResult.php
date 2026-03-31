<?php

declare(strict_types=1);

namespace LocalVisualDiff\Compare;

/**
 * Immutable summary of a screenshot comparison.
 *
 * The result is intentionally small and serializable so later tasks can store
 * it in JSON, pass it to report rendering, or log it without pulling in the
 * image-processing internals again.
 */
final class ComparisonResult
{
    /**
     * @param array<string, ?string> $artifacts
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $hasDiff,
        public readonly int $pixelCount,
        public readonly int $changedPixelCount,
        public readonly float $diffRatio,
        public readonly array $artifacts = []
    ) {
    }
}
