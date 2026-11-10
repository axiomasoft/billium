<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;

/** Host publication serializes version changes with correction application. */
#[Api]
#[Spi(SpiKind::Call)]
interface TaxCorrectionPolicyFrontier
{
    public function publish(TaxCorrectionPolicySnapshot $policy, string $commandKey): void;
}
