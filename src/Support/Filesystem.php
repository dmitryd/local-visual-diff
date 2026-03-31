<?php

declare(strict_types=1);

namespace LocalVisualDiff\Support;

use RuntimeException;

/**
 * Small filesystem helper so the runner can stay focused on the comparison
 * workflow instead of reimplementing path and file handling in-line.
 */
final class Filesystem
{
    /**
     * Join path segments using the current platform separator.
     *
     * @param string ...$segments
     */
    public function joinPath(string ...$segments): string
    {
        $filtered = [];

        foreach ($segments as $index => $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            if ($index === 0) {
                $filtered[] = rtrim($segment, DIRECTORY_SEPARATOR);
                continue;
            }

            $filtered[] = trim($segment, DIRECTORY_SEPARATOR);
        }

        return implode(DIRECTORY_SEPARATOR, $filtered);
    }

    public function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create directory: %s', $path));
        }
    }

    public function writeFile(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write file: %s', $path));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function writeJson(string $path, array $data): void
    {
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(sprintf('Could not encode JSON for %s: %s', $path, $exception->getMessage()), 0, $exception);
        }

        $this->writeFile($path, $json);
    }

    public function removeFileIfExists(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException(sprintf('Could not remove file: %s', $path));
        }
    }

    public function relativePath(string $baseDir, string $absolutePath): string
    {
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $baseDir)) {
            return substr($absolutePath, strlen($baseDir));
        }

        return $absolutePath;
    }
}
