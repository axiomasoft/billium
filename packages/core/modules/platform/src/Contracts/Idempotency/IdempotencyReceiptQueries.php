<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Idempotency;

use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Data\Idempotency\StoredCommandReceipt;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Authenticated, exact-scope reads; an unqualified historical receipt supplies no scope proof. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface IdempotencyReceiptQueries
{
    public function byCommandKey(FinancialScope $scope, string $schema, string $qualifiedKey): ?StoredCommandReceipt;

    public function byReceiptRef(FinancialScope $scope, string $class, string $receiptRef): ?StoredCommandReceipt;
}
