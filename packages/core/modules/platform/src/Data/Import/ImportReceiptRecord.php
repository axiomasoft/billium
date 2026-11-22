<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Immutable append-only audit receipt recorded for an external import record.
 */
#[Api(since: '1.0')]
final readonly class ImportReceiptRecord
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public int $id,
        public string $receiptRef,
        public int $batchPk,
        public ?int $recordPk,
        public ?int $mappingPk,
        public string $sourceIdentityDigest,
        public int $sourceRecordRevision,
        public string $payloadDigest,
        public ?string $sourceFactRef,
        public ImportReceiptStatus $status,
        public ?string $billiumReceiptRef,
        public array $details,
        public DateTimeImmutable $recordedAt,
    ) {}
}
