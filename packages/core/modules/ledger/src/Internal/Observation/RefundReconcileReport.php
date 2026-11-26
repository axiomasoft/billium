<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use InvalidArgumentException;
use Packman\Attributes\Internal;

/** Counts of one refund reconcile pass: charges compared, mismatches recorded and earlier mismatches resolved. */
#[Internal]
final readonly class RefundReconcileReport
{
    public function __construct(
        public int $compared = 0,
        public int $recorded = 0,
        public int $resolved = 0,
    ) {
        if ($compared < 0 || $recorded < 0 || $resolved < 0 || $recorded > $compared) {
            throw new InvalidArgumentException('Reconcile counts are nonnegative and record at most one mismatch per comparison');
        }
    }

    /** The report after one more comparison that recorded a mismatch or not and resolved $resolved earlier ones. */
    public function with(bool $recorded, int $resolved): self
    {
        return new self($this->compared + 1, $this->recorded + ($recorded ? 1 : 0), $this->resolved + $resolved);
    }
}
