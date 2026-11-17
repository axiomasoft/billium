<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * resolve(string $driverId,string $contractVersion): PaymentDriver; capabilities(MerchantRevision $merchant): CapabilitySnapshot. Unknown exact version refuses; never substitute latest.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PaymentDrivers
{
    public function resolve(string $driverId, string $contractVersion): PaymentDriver;

    public function capabilities(MerchantRevision $merchant): CapabilitySnapshot;
}
