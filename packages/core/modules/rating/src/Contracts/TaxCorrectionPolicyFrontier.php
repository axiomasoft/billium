<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host publication serializes version changes with correction application. */
#[Spi(SpiKind::Call)]
interface TaxCorrectionPolicyFrontier
{
    public function publish(TaxCorrectionPolicySnapshot $policy, string $commandKey): void;
}
