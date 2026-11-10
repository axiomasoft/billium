<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\Receipt;

#[Api]
final readonly class CorrectionReceipt implements Receipt
{
    public function __construct(
        public string $correctionRef, public string $parentOccurrenceRef, public int $correctionRevision,
        public bool $created, public string $registeredDigest,
    ) {}

    public function receiptClass(): string
    {
        return 'schedule.correction_registered';
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['correctionRef' => $this->correctionRef, 'parentOccurrenceRef' => $this->parentOccurrenceRef,
            'correctionRevision' => $this->correctionRevision, 'created' => $this->created,
            'registeredDigest' => $this->registeredDigest];
    }
}
