<?php

declare(strict_types=1);

namespace LocalVisualDiff\Support;

/**
 * TEMPORARY: remove this helper once the current performance investigation is complete.
 *
 * The intent is to keep profiling logic in one file so it can be deleted
 * cleanly later without having timing code scattered across the application.
 * The helper is deliberately small: it aggregates named durations and formats
 * them for terminal output when the `--profile` flag is enabled.
 */
final class RunProfiler
{
    /**
     * @var array<string, float>
     */
    private array $durations = [];

    private readonly float $startedAt;

    public function __construct(private readonly bool $enabled)
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Time one named operation and accumulate the duration under that name.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function measure(string $name, callable $callback): mixed
    {
        if (!$this->enabled) {
            return $callback();
        }

        $startedAt = microtime(true);

        try {
            return $callback();
        } finally {
            $this->durations[$name] = ($this->durations[$name] ?? 0.0) + (microtime(true) - $startedAt);
        }
    }

    /**
     * @return array<int, string>
     */
    public function formatLines(): array
    {
        if (!$this->enabled) {
            return [];
        }

        $lines = [];

        foreach ($this->durations as $name => $seconds) {
            $lines[] = sprintf('[profile] %-24s %8.1f ms', $name . ':', $seconds * 1000);
        }

        $lines[] = sprintf('[profile] %-24s %8.1f ms', 'total:', (microtime(true) - $this->startedAt) * 1000);

        return $lines;
    }
}
