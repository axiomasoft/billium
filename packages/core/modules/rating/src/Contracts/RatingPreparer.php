<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\RatingHold;
use Packman\Attributes\Api;

#[Api]
interface RatingPreparer
{
    public function prepare(PrepareRating $command): PreparedRating|RatingHold;
}
