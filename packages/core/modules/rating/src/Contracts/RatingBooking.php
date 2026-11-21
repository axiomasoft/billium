<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Data\Envelopes\CommandReceipt;
use Billium\Contracts\Data\Envelopes\PrepareObligation;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface RatingBooking
{
    public function book(BookRatedBasis $command): BookingReceipt;

    public function prepareObligation(PrepareObligation $command, PinnedRatingInputs $inputs): CommandReceipt;
}
