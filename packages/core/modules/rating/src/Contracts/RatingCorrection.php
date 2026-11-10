<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Correction\RatingCorrectionReceipt;
use Billium\Rating\Data\Rating\RatingHold;

#[Api]
#[Spi(SpiKind::Call)]
interface RatingCorrection
{
    public function prepare(PrepareCorrection $command): PreparedCorrection|RatingHold;

    public function apply(ApplyCorrection $command): RatingCorrectionReceipt;
}
