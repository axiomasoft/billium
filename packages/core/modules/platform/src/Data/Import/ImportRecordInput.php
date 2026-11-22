<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Data\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Normalized input record conforming to external.import.v1 without fabricated monetary defaults.
 */
#[Api(since: '1.0')]
final readonly class ImportRecordInput
{
    /**
     * @param  array<string, string>  $originalBusinessRefs
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  array<string, mixed>|null  $frozenPriceSnapshot
     * @param  array<string, mixed>|null  $fundingRefs
     * @param  array<string, mixed>|null  $legalDocument
     * @param  list<string>  $missingEvidence
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ImportSourceIdentity $identity,
        public string $batchId,
        public int $sourceExportRevision,
        public DateTimeImmutable $exportCutoff,
        public string $manifestDigest,
        public int $sourceRecordRevision,
        public DateTimeImmutable $eventBusinessDate,
        public string $payloadDigest,
        public array $originalBusinessRefs = [],
        public array $evidenceRefs = [],
        public ?Money $money = null,
        public ?array $frozenPriceSnapshot = null,
        public ?array $fundingRefs = null,
        public ?array $legalDocument = null,
        public ?string $importPolicyVersion = null,
        public ?string $sourceFactRef = null,
        public array $missingEvidence = [],
        public array $payload = [],
    ) {
        if (trim($batchId) === '' || strlen($batchId) > 200 || str_contains($batchId, "\0")) {
            throw new InvalidArgumentException('ImportRecordInput batchId must be a bounded nonempty string');
        }
        if ($sourceExportRevision < 0 || $sourceRecordRevision < 0) {
            throw new InvalidArgumentException('ImportRecordInput revisions must be non-negative');
        }
        foreach (['manifestDigest' => $manifestDigest, 'payloadDigest' => $payloadDigest] as $field => $digest) {
            if (preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
                throw new InvalidArgumentException("ImportRecordInput {$field} must be a valid sha256 digest");
            }
        }
        if ($importPolicyVersion !== null && (trim($importPolicyVersion) === '' || strlen($importPolicyVersion) > 200)) {
            throw new InvalidArgumentException('ImportRecordInput importPolicyVersion must be bounded when provided');
        }
        if ($sourceFactRef !== null && (trim($sourceFactRef) === '' || strlen($sourceFactRef) > 256)) {
            throw new InvalidArgumentException('ImportRecordInput sourceFactRef must be bounded when provided');
        }
    }
}
