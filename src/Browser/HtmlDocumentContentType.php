<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

/**
 * Classifies main-document response content types for screenshot capture.
 */
final class HtmlDocumentContentType
{
    /**
     * Return true when the response explicitly identifies an HTML document.
     */
    public static function isHtml(?string $contentType, ?string $mimeType = null): bool
    {
        $type = self::preferredType($contentType, $mimeType);

        return $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    /**
     * Return true only when the browser exposed an explicit non-HTML type.
     */
    public static function isKnownNonHtml(?string $contentType, ?string $mimeType = null): bool
    {
        $type = self::preferredType($contentType, $mimeType);

        return $type !== '' && !self::isHtml($type);
    }

    /**
     * Prefer the actual Content-Type header, falling back to CDP mimeType.
     */
    public static function preferredType(?string $contentType, ?string $mimeType = null): string
    {
        $normalizedContentType = self::normalize($contentType);

        if ($normalizedContentType !== '') {
            return $normalizedContentType;
        }

        return self::normalize($mimeType);
    }

    /**
     * Normalize a Content-Type value to its lowercase MIME type.
     */
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $parts = explode(';', $value, 2);

        return strtolower(trim($parts[0]));
    }
}
