<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface QualifiedObservationApplier extends ObservationApplier
{
    /** Called within the independently authenticated observation caller's isolated scope. */
    public function applyQualified(string $json, FinancialScope $scope, string $proofDigest): AllocationReceipt;
}
