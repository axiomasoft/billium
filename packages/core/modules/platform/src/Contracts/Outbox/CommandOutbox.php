<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Outbox;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CommandOutbox
{
    public function record(TransactionContext $context, PreparedInternalCommand $command): int;
}
