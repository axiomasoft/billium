<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Data\Envelopes\ObservationCustody;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * applyPaymentObservation: one authenticated payment.observation.v1 delivery
 * is taken into source-scoped custody and, when it is next in its partition, applied in ONE root Tx: observed fact →
 * applied projection (assigned cumulative totals, never deltas) → pro_rata_open/1 allocation → receipt and
 * outbox. Deliveries that become next are drained afterwards, each in its own root. The ledger never calls a PSP.
 *
 * A transport ACK of custody is not a business receipt: a parked, held-for-recovery or quarantined delivery
 * stays in custody, and the refusal is thrown only after that custody committed.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ObservationApplier
{
    /**
     * @param  string  $json  the payment.observation.v1 wire text
     *
     * @throws ProblemException before custody: billium.validation.invalid, billium.schema.unsupported, billium.auth.denied;
     *                          after commit: billium.observation.parked, billium.recovery.required, billium.observation.conflict
     */
    public function apply(string $json): AllocationReceipt;

    /** Return the durable parked disposition or the committed allocation receipt for this delivery. */
    public function applyWithCustody(string $json): AllocationReceipt|ObservationCustody;
}
