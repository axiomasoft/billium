<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Data\Envelopes\PrepareObligation;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Rating\Data\Rating\PreparedRating;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PinnedRatingInputs
{
    /** Resolve the exact agreement, component, occurrence, price version and snapshot digest in this scope. */
    public function resolve(FinancialScope $scope, PrepareObligation $command): ?PreparedRating;
}
