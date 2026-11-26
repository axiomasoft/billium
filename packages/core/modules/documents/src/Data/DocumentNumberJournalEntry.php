<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentNumberJournalEntry
{
    public function __construct(
        public int $id,
        public string $seriesRef,
        public string $periodKey,
        public int $sequenceNumber,
        public string $legalNumber,
        public string $legalDocumentRef,
        public string $eventKind,
        public ?string $reason,
        public string $occurredAt,
    ) {}
}
