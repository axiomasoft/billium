<?php

declare(strict_types=1);

namespace Billium\Core\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Data\Caller;

/**
 * Host SPI: the authenticated caller of the current request or job. The runner checks every operation's
 * seller/environment against it before any mutation and again on replay (auth.denied, receipt none). Billium binds a
 * default that throws until the host binds its own.
 */
#[Api]
#[Spi(SpiKind::Implement)]
interface CallerContext
{
    public function current(): Caller;
}
