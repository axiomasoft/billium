<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Observation;

use Billium\Contracts\Data\Money;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\AllocationReceipt;

/**
 * The exceptional-exposure vector: one source charge is
 * captured 100.00, refunded 70.00 consistently, then the provider reports refunded 140.00. The observed actual is kept
 * exactly (140.00), the applied projection stays at the last consistent 100.00/70.00, the exposure is 40.00, the
 * payment is held with spendable 0 and a new allocation from it is refused with the typed reallocation/hold code.
 * The ledger owns all of it in every profile; nothing calls a PSP.
 */
trait OverRefundVector
{
    /**
     * @return array{consistent: AllocationReceipt, held: AllocationReceipt, refusal: string|null}
     */
    protected function overRefund(string $authority): array
    {
        $this->debt('o1', 1000);
        $this->debt('o2', 1000);
        $this->intent('intent-a', ['o1', 'o2']);
        $fields = ['sourceAuthority' => $authority];
        $this->observe(ObservationFactory::charge('op-1', 1, 10000, sequence: 1, fields: $fields));
        $consistent = $this->observe(ObservationFactory::charge('op-1', 2, 10000, 7000, sequence: 2, fields: $fields));
        $held = $this->observe(ObservationFactory::charge('op-1', 3, 10000, 14000, sequence: 3, fields: $fields));
        $refusal = $this->ledger()->allocate(new Allocate('key-after-hold', $this->account(), 'after-hold', 'o1', Money::parse('1.00', 'RUB'), 'op-1'));

        return ['consistent' => $consistent, 'held' => $held, 'refusal' => $refusal->problem?->value];
    }
}
