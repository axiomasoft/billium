<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Queries;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\OperationRecord;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class ExecutionView
{
    /** @param array<string, PaymentObservation> $observations  Opaque command reference is the key. */
    public function __construct(
        public OperationRecord $operation,
        public CapacitySnapshot $capacity,
        public string $status,
        public bool $providerOutcomeRecorded,
        public ?stdClass $absenceCertificate,
        public array $observations,
    ) {}
}
