<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\CollectionGuardPlan;
use Billium\Execution\Data\OperationRecord;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** The registered collection owner closes its unsent step/reservation and any ledger association after the local cancellation CAS, in this same root. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CollectionCancellationParticipant
{
    public function closeUnsent(TransactionContext $ctx, CollectionGuardPlan $plan, OperationRecord $operation, string $executionCancelReceiptRef): string;
}
