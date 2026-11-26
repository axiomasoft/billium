<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Outbox;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Narrow observation lane; never authorizes a provider effect in recovery. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface FactualCommandOutbox
{
    public function record(TransactionContext $context, PreparedFactualCommand $command): int;
}
