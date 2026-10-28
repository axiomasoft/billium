<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;

#[Api]
#[Spi(SpiKind::Call)]
interface CommandOutbox
{
    public function record(TransactionContext $context, PreparedInternalCommand $command): int;
}
