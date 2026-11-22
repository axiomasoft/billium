<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Outcome recorded for a single staged import record during apply or resume.
 */
#[Api(since: '1.0')]
final readonly class ImportRecordApplyOutcome
{
    public function __construct(
        public ImportSourceIdentity $identity,
        public int $sourceRecordRevision,
        public string $sourceFactRef,
        public ImportRecordApplyStatus $status,
        public ?string $mappingRef = null,
        public ?string $importReceiptRef = null,
        public ?string $billiumTargetKind = null,
        public ?string $billiumTargetRef = null,
        public ?string $detail = null,
    ) {}

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        $wire = [
            'sourceRecordId' => $this->identity->sourceRecordId,
            'recordKind' => $this->identity->recordKind->value,
            'sourceRecordRevision' => $this->sourceRecordRevision,
            'sourceFactRef' => $this->sourceFactRef,
            'status' => $this->status->value,
        ];
        if ($this->mappingRef !== null) {
            $wire['mappingRef'] = $this->mappingRef;
        }
        if ($this->importReceiptRef !== null) {
            $wire['importReceiptRef'] = $this->importReceiptRef;
        }
        if ($this->billiumTargetKind !== null) {
            $wire['billiumTargetKind'] = $this->billiumTargetKind;
        }
        if ($this->billiumTargetRef !== null) {
            $wire['billiumTargetRef'] = $this->billiumTargetRef;
        }
        if ($this->detail !== null) {
            $wire['detail'] = $this->detail;
        }

        return $wire;
    }
}
