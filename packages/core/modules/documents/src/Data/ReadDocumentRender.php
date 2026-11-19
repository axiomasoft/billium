<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ReadDocumentRender
{
    /** @param list<string>|null $allowedActorRefs */
    public function __construct(
        public DocumentScope $scope,
        public string $legalDocumentRef,
        public string $templateId,
        public string $locale,
        public int $ttlSeconds = 300,
        public ?string $renderRequestRef = null,
        public ?array $allowedActorRefs = null,
    ) {}
}
