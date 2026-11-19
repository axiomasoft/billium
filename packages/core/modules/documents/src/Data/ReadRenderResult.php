<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ReadRenderResult
{
    public function __construct(
        public string $renderRequestRef,
        public string $legalDocumentRef,
        public int $documentRevision,
        public int $renderRevision,
        public string $blobRef,
        public string $outputDigest,
        public string $contentType,
        public int $byteSize,
        public string $temporaryUrl,
        public string $expiresAt,
    ) {}
}
