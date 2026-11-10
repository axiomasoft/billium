<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;

#[Api]
#[Spi(SpiKind::Implement)]
interface TaxSnapshotPort
{
    /** Resolve outside any database transaction; missing evidence must fail explicitly. */
    public function resolve(TaxResolutionRequest $request): TaxSnapshot;

    /** Resolve outside any database transaction; unsupported correction policy must fail explicitly. */
    public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot;
}
