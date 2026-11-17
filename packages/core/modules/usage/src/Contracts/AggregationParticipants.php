<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Usage\Data\AggregateSnapshotRef;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface AggregationParticipants
{
    public function assertSnapshotCurrent(TransactionContext $context, AggregateSnapshotRef $ref): void;
}
