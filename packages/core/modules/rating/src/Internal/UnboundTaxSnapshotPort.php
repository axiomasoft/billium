<?php

declare(strict_types=1);

namespace Billium\Rating\Internal;

use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class UnboundTaxSnapshotPort implements TaxSnapshotPort
{
    public function resolve(TaxResolutionRequest $request): TaxSnapshot
    {
        throw new LogicException('Host must bind TaxSnapshotPort');
    }

    public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
    {
        throw new LogicException('Host must bind TaxSnapshotPort');
    }
}
