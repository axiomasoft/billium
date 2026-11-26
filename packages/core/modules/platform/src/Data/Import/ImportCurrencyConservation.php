<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Read-only per-currency ledger conservation snapshot for import reconciliation.
 */
#[Api(since: '1.0')]
final readonly class ImportCurrencyConservation
{
    public function __construct(
        public string $currency,
        public int $obligationsDueMinor,
        public int $obligationsAdjustedMinor,
        public int $obligationsCreditedMinor,
        public int $obligationsSettledMinor,
        public int $obligationsWrittenOffMinor,
        public int $obligationsOpenMinor,
        public int $paymentsCapturedMinor,
        public int $paymentsRefundedMinor,
        public int $paymentsAllocatedMinor,
        public int $allocationsLiveMinor,
        public bool $conserved,
    ) {}

    /** @return array{obligationsDueMinor: int, obligationsOpenMinor: int, obligationsSettledMinor: int, obligationsCreditedMinor: int, paymentsCapturedMinor: int, paymentsRefundedMinor: int, paymentsAllocatedMinor: int, allocationsLiveMinor: int, conserved: bool} */
    public function toSummary(): array
    {
        return [
            'obligationsDueMinor' => $this->obligationsDueMinor,
            'obligationsOpenMinor' => $this->obligationsOpenMinor,
            'obligationsSettledMinor' => $this->obligationsSettledMinor,
            'obligationsCreditedMinor' => $this->obligationsCreditedMinor,
            'paymentsCapturedMinor' => $this->paymentsCapturedMinor,
            'paymentsRefundedMinor' => $this->paymentsRefundedMinor,
            'paymentsAllocatedMinor' => $this->paymentsAllocatedMinor,
            'allocationsLiveMinor' => $this->allocationsLiveMinor,
            'conserved' => $this->conserved,
        ];
    }
}
