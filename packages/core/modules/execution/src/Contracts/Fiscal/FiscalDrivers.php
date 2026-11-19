<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts\Fiscal;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Exact (driverId, contractVersion) negotiation registry for fiscal drivers. Unknown versions refuse; never substitutes latest.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FiscalDrivers
{
    public function resolve(string $driverId, string $contractVersion): FiscalDriver;

    public function supports(string $driverId, string $contractVersion): bool;
}
