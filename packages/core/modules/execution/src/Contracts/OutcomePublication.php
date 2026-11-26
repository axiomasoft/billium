<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\MaterialChange;
use Billium\Execution\Data\PublicationReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * emit(TransactionContext $ctx,MaterialChange $change): PublicationReceipt; implemented by Internal/Publication/OutcomeEmitter.php EVIDENCE_CAPACITY. DELlVERY_QUERIES consumes only; no edits to emitter.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface OutcomePublication
{
    public function emit(TransactionContext $ctx, MaterialChange $change): PublicationReceipt;
}
