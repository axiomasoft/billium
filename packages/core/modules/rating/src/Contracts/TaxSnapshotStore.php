<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxSnapshot;

#[Api]
#[Spi(SpiKind::Call)]
interface TaxSnapshotStore
{
    /** The caller resolves and verifies the host evidence before invoking this root operation. */
    public function freeze(TaxSnapshot $snapshot, string $commandKey): void;

    public function freezeCorrection(TaxCorrectionPolicySnapshot $policy, string $commandKey): void;
}
