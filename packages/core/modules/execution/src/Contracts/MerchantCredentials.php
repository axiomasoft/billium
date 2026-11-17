<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ResolvedCredentials;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * resolve(MerchantRevision $revision,string $purpose): ResolvedCredentials outside Tx; account/purpose continuity must match original binding; rotation may replace secret version for same historical account only with authenticated continuity proof
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MerchantCredentials
{
    public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials;
}
