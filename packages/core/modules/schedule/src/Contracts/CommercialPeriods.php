<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Data\Envelopes\CommercialPeriod;
use Billium\Schedule\Data\PeriodQuote;
use Packman\Attributes\Api;

#[Api]
interface CommercialPeriods
{
    /** Export frozen quote evidence through the existing negotiated wire contract, without recalculation. */
    public function fromQuote(PeriodQuote $quote): CommercialPeriod;
}
