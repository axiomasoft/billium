<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Data\Runner\LockRequest;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Discover the ledger-owned rank-65 row before composing a financial root. The participant rechecks it under the lock. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface SettlementIntentLocks
{
    public function plan(BillingAccountScope $scope, string $businessIntentRef): LockRequest;
}
