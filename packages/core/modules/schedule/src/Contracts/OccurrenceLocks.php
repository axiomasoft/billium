<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Outer roots acquire the complete rank-50 set before settlement/obligation locks. */
#[Spi(SpiKind::Call)]
interface OccurrenceLocks
{
    /** @param list<string> $occurrenceRefs */
    public function acquire(TransactionContext $context, array $occurrenceRefs): void;
}
