<?php

declare(strict_types=1);

namespace LocalVisualDiff\Report;

/**
 * Convert the raw comparison results into the shape the Fluid templates expect.
 *
 * The rest of the application should be free to keep using its own internal
 * data structures. This factory centralizes the final presentation-friendly
 * normalization step so the templates stay simple and boring.
 */
final class ReportViewModelFactory
{
    /**
     * Build the report view model from a summary array and a list of page rows.
     *
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public function create(array $summary, array $results): array
    {
        $rows = array_map(
            fn (array $result): array => $this->normalizeResult($result),
            $results
        );

        return [
            'summary' => $this->normalizeSummary($summary, $rows),
            'results' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function normalizeSummary(array $summary, array $rows): array
    {
        $counts = $this->countStatuses($rows);

        return [
            'baselineBaseUrl' => (string) ($summary['baselineBaseUrl'] ?? ''),
            'candidateBaseUrl' => (string) ($summary['candidateBaseUrl'] ?? ''),
            'generatedAt' => (string) ($summary['generatedAt'] ?? date('Y-m-d H:i:s')),
            'totalPages' => (int) ($summary['totalPages'] ?? count($rows)),
            'matchingPages' => (int) ($summary['matchingPages'] ?? $counts['matching']),
            'differentPages' => (int) ($summary['differentPages'] ?? $counts['different']),
            'httpErrorPages' => (int) ($summary['httpErrorPages'] ?? $counts['http_error']),
            'runtimeErrorPages' => (int) ($summary['runtimeErrorPages'] ?? $counts['runtime_error']),
            'shiftTolerance' => (int) ($summary['shiftTolerance'] ?? 0),
            'captureMatchingScreenshots' => (bool) ($summary['captureMatchingScreenshots'] ?? false),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function countStatuses(array $rows): array
    {
        $counts = [
            'matching' => 0,
            'different' => 0,
            'http_error' => 0,
            'runtime_error' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'runtime_error');

            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizeResult(array $result): array
    {
        $status = $this->normalizeStatus((string) ($result['status'] ?? 'runtime_error'));
        $httpStatusBaseline = $this->extractHttpStatus($result, 'baseline');
        $httpStatusCandidate = $this->extractHttpStatus($result, 'candidate');
        $statusLabel = $this->statusLabel($status, $httpStatusBaseline, $httpStatusCandidate);
        $artifacts = is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [];
        $title = trim((string) ($result['title'] ?? ''));

        if ($title === '') {
            $title = trim((string) ($result['path'] ?? ''));
        }

        if ($title === '') {
            $title = trim((string) ($result['baselineUrl'] ?? $result['candidateUrl'] ?? ''));
        }

        return [
            'status' => $status,
            'statusLabel' => $statusLabel,
            'statusClass' => $this->statusClass($status),
            'title' => $title,
            'path' => (string) ($result['path'] ?? ''),
            'baselineUrl' => (string) ($result['baselineUrl'] ?? ''),
            'candidateUrl' => (string) ($result['candidateUrl'] ?? ''),
            'baselineScreenshotUrl' => $this->artifactUrl($artifacts['baseline'] ?? null),
            'candidateScreenshotUrl' => $this->artifactUrl($artifacts['candidate'] ?? null),
            'diffUrl' => $this->artifactUrl($artifacts['diff'] ?? null),
            'contextUrl' => $this->artifactUrl($artifacts['context'] ?? null),
            'overlayUrl' => $this->artifactUrl($artifacts['overlay'] ?? null),
            'httpStatusBaseline' => $httpStatusBaseline,
            'httpStatusCandidate' => $httpStatusCandidate,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'matching', 'different', 'http_error', 'runtime_error', 'skipped' => $status,
            'error' => 'runtime_error',
            default => 'runtime_error',
        };
    }

    private function statusClass(string $status): string
    {
        return 'status-dot--' . str_replace('_', '-', $status);
    }

    private function statusLabel(string $status, int $httpStatusBaseline, int $httpStatusCandidate): string
    {
        return match ($status) {
            'matching' => 'matching',
            'different' => 'different',
            'http_error' => $this->httpErrorLabel($httpStatusBaseline, $httpStatusCandidate),
            'runtime_error' => 'runtime error',
            'skipped' => 'skipped',
            default => $status,
        };
    }

    private function httpErrorLabel(int $httpStatusBaseline, int $httpStatusCandidate): string
    {
        if ($httpStatusBaseline >= 400) {
            return sprintf('HTTP error %d', $httpStatusBaseline);
        }

        if ($httpStatusCandidate >= 400) {
            return sprintf('HTTP error %d', $httpStatusCandidate);
        }

        return 'HTTP error';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractHttpStatus(array $result, string $side): int
    {
        $directKey = 'httpStatus' . ucfirst($side);

        if (isset($result[$directKey]) && is_numeric($result[$directKey])) {
            return (int) $result[$directKey];
        }

        $nested = $result['httpStatus'] ?? null;

        if (is_array($nested) && isset($nested[$side]) && is_numeric($nested[$side])) {
            return (int) $nested[$side];
        }

        return 0;
    }

    private function artifactUrl(mixed $artifactPath): ?string
    {
        if (!is_string($artifactPath) || $artifactPath === '') {
            return null;
        }

        return $artifactPath;
    }
}
