<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Schedule\Data\Commands\AdvanceOccurrence;

#[Api]
#[Spi(SpiKind::Call)]
interface OccurrenceParticipants
{
    public function advanceOccurrence(TransactionContext $context, AdvanceOccurrence $command): Receipt;
}
