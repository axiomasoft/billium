<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Inbox;

use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\VerifiedObservationBootstrap;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ObservationBootstrapAuthenticator
{
    /** Independently verify signature, origin, frozen root/account, lineage, complete manifest and retention lease at Tx0. */
    public function authenticate(BootstrapPartition $snapshot): VerifiedObservationBootstrap;
}
