<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Ledger\Data\Allocations\ObservationCheckpoint;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Exact proven observation revision, independently of transport acknowledgement or later root state. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ObservationCheckpointQueries
{
    public function checkpoint(FinancialScope $scope, PaymentObservation $observation): ?ObservationCheckpoint;
}
