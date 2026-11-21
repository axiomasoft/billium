<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Idempotency;

use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use stdClass;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ReceiptAbsenceAuthority
{
    /** Certify completeness for this authenticated selection and reference, including retained history. Null means unknown. */
    public function confirmAbsent(Caller $caller, FinancialScope $scope, string $receiptRef): ?stdClass;
}
