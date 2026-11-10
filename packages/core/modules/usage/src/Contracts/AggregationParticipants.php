<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Usage\Data\AggregateSnapshotRef;

#[Api]
#[Spi(SpiKind::Call)]
interface AggregationParticipants
{
    public function assertSnapshotCurrent(TransactionContext $context, AggregateSnapshotRef $ref): void;
}
