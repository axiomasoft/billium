<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\RenderState;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentRenderView
{
    /** @param array<string, mixed> $redactedDiagnostics */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $renderRequestRef,
        public string $legalDocumentRef,
        public int $documentRevision,
        public string $approvedSnapshotDigest,
        public string $templateId,
        public string $templateVersion,
        public string $templateDigest,
        public string $locale,
        public string $rendererName,
        public string $rendererVersion,
        public string $rendererDigest,
        public string $renderKeyDigest,
        public int $renderRevision,
        public int $attempt,
        public RenderState $state,
        public ?string $blobRef,
        public ?string $outputDigest,
        public ?string $contentType,
        public ?int $byteSize,
        public array $redactedDiagnostics,
    ) {}
}
