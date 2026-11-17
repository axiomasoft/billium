<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\Caller;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host SPI: the authenticated caller of the current request or job. The runner checks every operation's
 * seller/environment against it before any mutation and again on replay (auth.denied, receipt none). Billium binds a
 * default that throws until the host binds its own.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CallerContext
{
    public function current(): Caller;
}
