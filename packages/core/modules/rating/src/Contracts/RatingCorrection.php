<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Correction\RatingCorrectionReceipt;
use Billium\Rating\Data\Rating\RatingHold;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface RatingCorrection
{
    public function prepare(PrepareCorrection $command): PreparedCorrection|RatingHold;

    public function apply(ApplyCorrection $command): RatingCorrectionReceipt;
}
