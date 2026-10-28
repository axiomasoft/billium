<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\AllocationReceipt;

/**
 * applyPaymentObservation (REQ-CON-13, REQ-OWN-11, D28, D41, D46, D60): one authenticated payment.observation.v1 delivery
 * is taken into source-scoped custody and, when it is next in its partition, applied in ONE root Tx: observed fact →
 * applied projection (assigned cumulative totals, never deltas, INV-06) → pro_rata_open/1 allocation → receipt and
 * outbox (PF-12). Deliveries that become next are drained afterwards, each in its own root. The ledger never calls a PSP.
 *
 * A transport ACK of custody is not a business receipt (INV-08): a parked, held-for-recovery or quarantined delivery
 * stays in custody, and the refusal is thrown only after that custody committed (D31).
 */
#[Api]
#[Spi(SpiKind::Call)]
interface ObservationApplier
{
    /**
     * @param  string  $json  the payment.observation.v1 wire text
     *
     * @throws ProblemException before custody: billium.validation.invalid, billium.schema.unsupported, billium.auth.denied;
     *                          after commit: billium.observation.parked, billium.recovery.required, billium.observation.conflict
     */
    public function apply(string $json): AllocationReceipt;
}
