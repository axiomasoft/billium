<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentFinalizationEventView
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public int $id,
        public int $finalizationId,
        public int $finalizationRevision,
        public string $eventKind,
        public string $inputDigest,
        public array $details,
        public string $occurredAt,
    ) {}
}
