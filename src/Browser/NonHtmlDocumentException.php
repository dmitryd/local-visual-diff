<?php

declare(strict_types=1);

namespace LocalVisualDiff\Browser;

/**
 * Signals that the main browser navigation resolved to a non-HTML document.
 */
final class NonHtmlDocumentException extends \RuntimeException
{
    public function __construct(
        public readonly string $url,
        public readonly int $httpStatus,
        public readonly string $contentType,
        public readonly string $side = ''
    ) {
        parent::__construct(sprintf(
            'Skipping non-HTML document %s: HTTP %d, Content-Type %s',
            $url,
            $httpStatus,
            $contentType !== '' ? $contentType : '-'
        ));
    }
}
