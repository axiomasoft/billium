<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Inbox;

use Billium\Core\Data\Runtime\Caller;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface AuthenticatedObservationBootstrap
{
    /**
     * @template T
     *
     * @param  Closure(VerifiedObservationBootstrap):T  $consume
     * @return T
     */
    public function run(BootstrapPartition $snapshot, Closure $consume): mixed;

    public function assertCurrent(Caller $caller, BootstrapPartition $snapshot, VerifiedObservationBootstrap $grant): void;
}
