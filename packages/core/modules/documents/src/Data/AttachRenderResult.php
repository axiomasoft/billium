<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AttachRenderResult
{
    /** @param array<string, mixed> $diagnostics */
    public function __construct(
        public DocumentScope $scope,
        public string $renderRequestRef,
        public int $renderRevision,
        public string $renderKeyDigest,
        public string $blobRef,
        public string $outputDigest,
        public string $contentType,
        public int $byteSize,
        public array $diagnostics = [],
    ) {}
}
