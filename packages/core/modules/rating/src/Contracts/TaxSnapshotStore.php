<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface TaxSnapshotStore
{
    /** The caller resolves and verifies the host evidence before invoking this root operation. */
    public function freeze(TaxSnapshot $snapshot, string $commandKey): void;

    public function freezeCorrection(TaxCorrectionPolicySnapshot $policy, string $commandKey): void;
}
