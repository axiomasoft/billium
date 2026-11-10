<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\BookRatedBasis;

#[Api]
#[Spi(SpiKind::Call)]
interface RatingBooking
{
    public function book(BookRatedBasis $command): BookingReceipt;
}
