<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RequestDocumentRender
{
    /** @param list<string> $assetRefs */
    public function __construct(
        public DocumentScope $scope,
        public string $renderRequestRef,
        public string $legalDocumentRef,
        public string $templateId,
        public string $templateVersion,
        public string $templateDigest,
        public string $locale,
        public string $rendererName,
        public string $rendererVersion,
        public string $rendererDigest,
        public array $assetRefs = [],
        public ?DateTimeImmutable $retentionPinnedUntil = null,
    ) {}
}
