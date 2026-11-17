<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Schedule\Data\Commands\AdvanceOccurrence;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface OccurrenceParticipants
{
    public function advanceOccurrence(TransactionContext $context, AdvanceOccurrence $command): Receipt;
}
