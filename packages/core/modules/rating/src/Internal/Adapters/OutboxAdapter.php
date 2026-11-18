<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Rating\Data\Correction\RatingCorrectionReceipt;
use Packman\Attributes\Internal;

/** Rating's route to the platform command outbox. */
#[Internal]
final readonly class OutboxAdapter
{
    public function __construct(private CommandOutbox $outbox) {}

    /** Records the applied correction in the same root transaction so its follow-up work is never lost or duplicated. */
    public function correctionApplied(TransactionContext $context, RatingCorrectionReceipt $receipt): void
    {
        $this->outbox->record(context: $context, command: new PreparedInternalCommand(commandRef: 'rating:'.$receipt->correctionRef,
            businessRef: $receipt->correctionRef, ownerModuleId: 'rating', commandType: 'rating.correction.applied.v1',
            commandSchema: 'billium.command.v1', payload: $receipt->body(), destinations: ['rating']));
    }
}
