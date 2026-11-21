<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Data\Envelopes\EnsureBillingOccurrence;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PinnedOccurrenceInputs
{
    /** Resolve the schedule, ordinal and input digest for the requested historical revision and interval. */
    public function resolve(FinancialScope $scope, EnsureBillingOccurrence $command): ?EnsureOccurrence;
}
