<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;

/** Outer roots acquire the complete rank-50 set before settlement/obligation locks (D27/FA03). */
#[Api]
#[Spi(SpiKind::Call)]
interface OccurrenceLocks
{
    /** @param list<string> $occurrenceRefs */
    public function acquire(TransactionContext $context, array $occurrenceRefs): void;
}
