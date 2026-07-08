<?php

declare(strict_types=1);

namespace LocalVisualDiff\Support;

use Imagick;
use RuntimeException;

/**
 * Write retained report artifacts in one configurable image format.
 *
 * The comparison engine should keep using lossless temporary PNG screenshots so
 * pixel matching stays exact. This helper is only for the files that remain in
 * `var/results/...` after the run finishes. It resolves the preferred output
 * format once, falls back to PNG when the local Imagick build cannot write the
 * requested format, and applies format-specific encoder settings in one place.
 */
final class ArtifactImageWriter
{
    /**
     * @var array<string, true>|null
     */
    private ?array $supportedFormats = null;

    private ?string $resolvedFormat = null;

    public function __construct(private readonly string $preferredFormat = 'webp')
    {
    }

    /**
     * Return the actual format that will be written on this machine.
     */
    public function resolvedFormat(): string
    {
        if ($this->resolvedFormat !== null) {
            return $this->resolvedFormat;
        }

        $preferred = $this->normalizeFormatName($this->preferredFormat);
        $supportedFormats = $this->supportedFormats();

        if (isset($supportedFormats[strtoupper($preferred)])) {
            $this->resolvedFormat = $preferred;

            return $this->resolvedFormat;
        }

        $this->resolvedFormat = 'png';

        return $this->resolvedFormat;
    }

    /**
     * Build a file path using the actual resolved output format.
     */
    public function artifactPath(string $directory, string $baseName): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $baseName
            . '.'
            . $this->fileExtension($this->resolvedFormat());
    }

    /**
     * Write one Imagick image in the resolved artifact format.
     */
    public function writeImage(Imagick $image, string $outputPath): string
    {
        $this->ensureDirectoryExists($outputPath);

        $format = $this->resolvedFormat();
        $clone = clone $image;
        $clone->stripImage();

        switch ($format) {
            case 'webp':
                $clone->setOption('webp:lossless', 'true');
                $clone->setImageCompressionQuality(100);
                break;
            case 'avif':
                $clone->setOption('heic:speed', '6');
                $clone->setImageCompressionQuality(100);
                break;
            case 'jpeg':
                $clone->setImageCompressionQuality(92);
                break;
            case 'png':
                $clone->setImageCompressionQuality(100);
                break;
        }

        $clone->setImageFormat($format);

        try {
            if ($clone->writeImage($outputPath) === false) {
                throw new RuntimeException(sprintf('Could not write artifact image: %s', $outputPath));
            }

            return $outputPath;
        } finally {
            $clone->clear();
        }
    }

    /**
     * Normalize user-facing aliases like `jpg` into the encoder names Imagick
     * expects.
     */
    private function normalizeFormatName(string $format): string
    {
        $format = strtolower(trim($format));

        return match ($format) {
            '', 'jpg' => 'jpeg',
            default => $format,
        };
    }

    /**
     * Use common web-facing extensions even when Imagick prefers a different
     * internal codec name.
     */
    private function fileExtension(string $format): string
    {
        return match ($format) {
            'jpeg' => 'jpg',
            default => $format,
        };
    }

    /**
     * @return array<string, true>
     */
    private function supportedFormats(): array
    {
        if ($this->supportedFormats !== null) {
            return $this->supportedFormats;
        }

        $supportedFormats = [];

        foreach (Imagick::queryFormats() as $format) {
            $supportedFormats[strtoupper($format)] = true;
        }

        $this->supportedFormats = $supportedFormats;

        return $this->supportedFormats;
    }

    private function ensureDirectoryExists(string $outputPath): void
    {
        $directory = dirname($outputPath);

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create output directory: %s', $directory));
        }
    }
}
