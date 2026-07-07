<?php

declare(strict_types=1);

namespace LocalVisualDiff\Run;

/**
 * Immutable description of one compared page.
 *
 * The runner keeps this object intentionally plain so it can be converted into
 * a report view model, JSON output, or console diagnostics without re-running
 * the comparison logic.
 */
final class PageResult
{
    /**
     * @param array<string, ?string> $artifacts
     */
    public function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly string $status,
        public readonly string $baselineUrl,
        public readonly string $candidateUrl,
        public readonly int $httpStatusBaseline,
        public readonly int $httpStatusCandidate,
        public readonly array $artifacts = [],
        public readonly string $contentTypeBaseline = '',
        public readonly string $contentTypeCandidate = '',
        public readonly string $skipReason = ''
    ) {
    }

    /**
     * Convert the result into a serializable array for JSON/report rendering.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'title' => $this->title,
            'status' => $this->status,
            'baselineUrl' => $this->baselineUrl,
            'candidateUrl' => $this->candidateUrl,
            'httpStatusBaseline' => $this->httpStatusBaseline,
            'httpStatusCandidate' => $this->httpStatusCandidate,
            'artifacts' => $this->artifacts,
            'contentTypeBaseline' => $this->contentTypeBaseline,
            'contentTypeCandidate' => $this->contentTypeCandidate,
            'skipReason' => $this->skipReason,
        ];
    }
}
