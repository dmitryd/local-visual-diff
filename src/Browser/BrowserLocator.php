<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

use RuntimeException;

/**
 * Resolve a usable browser binary path for the current machine.
 *
 * The visual diff tool is intentionally local-first, so the browser path must
 * be resolved without any network dependency. The lookup order is:
 * 1. user-provided path
 * 2. common defaults for the current operating system
 *
 * The locator accepts an optional list of known existing paths so unit tests
 * can exercise the lookup logic without depending on the real filesystem.
 */
final class BrowserLocator
{
    /**
     * @param array<int, string> $existingPaths
     * @param bool               $checkFilesystem false in tests when the real
     *                                            machine may have extra browsers
     *                                            installed that would affect
     *                                            deterministic assertions.
     */
    public function __construct(
        private readonly array $existingPaths = [],
        private readonly bool $checkFilesystem = true
    ) {
    }

    /**
     * Resolve a browser path.
     *
     * @param string|null $configuredPath path provided by the user or config
     * @param string|null $osFamily       override the detected OS family in tests
     */
    public function resolve(?string $configuredPath = null, ?string $osFamily = null): string
    {
        $candidatePaths = [];

        $configuredPath = $this->normalizePath($configuredPath);
        if ($configuredPath !== null) {
            $candidatePaths[] = $configuredPath;
        }

        foreach ($this->defaultCandidatesFor($osFamily ?? PHP_OS_FAMILY) as $candidatePath) {
            $candidatePaths[] = $candidatePath;
        }

        foreach (array_values(array_unique($candidatePaths)) as $candidatePath) {
            if ($this->isUsableExecutable($candidatePath)) {
                return $candidatePath;
            }
        }

        throw new RuntimeException(sprintf(
            'Could not find a supported browser executable. Checked: %s',
            implode(', ', $candidatePaths)
        ));
    }

    /**
     * @return array<int, string>
     */
    private function defaultCandidatesFor(string $osFamily): array
    {
        return match ($osFamily) {
            'Darwin' => [
                '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Chromium.app/Contents/MacOS/Chromium',
            ],
            'Linux' => [
                '/usr/bin/brave-browser',
                '/usr/bin/google-chrome',
                '/usr/bin/chromium',
                '/usr/bin/chromium-browser',
            ],
            default => [],
        };
    }

    private function normalizePath(?string $path): ?string
    {
        $path = $path === null ? null : trim($path);

        return $path === '' ? null : $path;
    }

    private function isUsableExecutable(string $path): bool
    {
        if (in_array($path, $this->existingPaths, true)) {
            return true;
        }

        if (!$this->checkFilesystem) {
            return false;
        }

        return is_file($path) && is_executable($path);
    }
}
