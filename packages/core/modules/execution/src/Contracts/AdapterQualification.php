<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Enums\EffectKind;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Trusted external verifier checks exact account, adapter version, environment and effect evidence before the transaction. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface AdapterQualification
{
    public function verify(MerchantRevision $merchant, CapabilitySnapshot $capability, EffectKind $kind): void;
}
