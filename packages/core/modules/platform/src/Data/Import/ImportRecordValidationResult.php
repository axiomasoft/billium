<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Typed validation outcome for one external.import.v1 record during preview evaluation.
 */
#[Api(since: '1.0')]
final readonly class ImportRecordValidationResult
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $missingEvidence
     */
    public function __construct(
        public ImportSourceIdentity $identity,
        public int $sourceRecordRevision,
        public string $sourceIdentityDigest,
        public string $payloadDigest,
        public ImportValidationStatus $status,
        public array $reasonCodes = [],
        public array $missingEvidence = [],
        public ?string $rejectionCode = null,
        public ?string $existingMappingRef = null,
        public ?string $receiptRef = null,
        public ?string $sourceFactRef = null,
    ) {
        if ($sourceRecordRevision < 0) {
            throw new InvalidArgumentException('sourceRecordRevision must be non-negative');
        }
        foreach (['sourceIdentityDigest' => $sourceIdentityDigest, 'payloadDigest' => $payloadDigest] as $field => $digest) {
            if (preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
                throw new InvalidArgumentException("ImportRecordValidationResult {$field} must be a valid sha256 digest");
            }
        }
        if ($status === ImportValidationStatus::UnanchoredEvidence && ($reasonCodes === [] || $missingEvidence === [])) {
            throw new InvalidArgumentException('unanchored_evidence outcome requires nonempty reasonCodes and missingEvidence');
        }
        if ($status === ImportValidationStatus::Rejected && ($rejectionCode === null || trim($rejectionCode) === '')) {
            throw new InvalidArgumentException('rejected outcome requires a nonempty rejectionCode');
        }
        if ($status === ImportValidationStatus::Conflict && ($existingMappingRef === null || trim($existingMappingRef) === '')) {
            throw new InvalidArgumentException('conflict outcome requires existingMappingRef');
        }
        if ($status === ImportValidationStatus::AlreadyApplied && ($receiptRef === null || trim($receiptRef) === '')) {
            throw new InvalidArgumentException('already_applied outcome requires receiptRef');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        $wire = [
            'sourceRecordId' => $this->identity->sourceRecordId,
            'sourceRecordRevision' => $this->sourceRecordRevision,
            'recordKind' => $this->identity->recordKind->value,
            'sourceIdentityDigest' => $this->sourceIdentityDigest,
            'payloadDigest' => $this->payloadDigest,
            'status' => $this->status->value,
        ];
        if ($this->reasonCodes !== []) {
            $wire['reasonCodes'] = $this->reasonCodes;
        }
        if ($this->missingEvidence !== []) {
            $wire['missingEvidence'] = $this->missingEvidence;
        }
        if ($this->rejectionCode !== null) {
            $wire['rejectionCode'] = $this->rejectionCode;
        }
        if ($this->existingMappingRef !== null) {
            $wire['existingMappingRef'] = $this->existingMappingRef;
        }
        if ($this->receiptRef !== null) {
            $wire['receiptRef'] = $this->receiptRef;
        }
        if ($this->sourceFactRef !== null) {
            $wire['sourceFactRef'] = $this->sourceFactRef;
        }

        return $wire;
    }
}
