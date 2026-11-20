<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Ledger-owned collection eligibility under the obligation lock (D35, D60, D63). */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CollectionObligation
{
    public function lockCollectibleMinor(TransactionContext $context, BillingAccountScope $scope, string $obligationRef, string $intentRef, string $currency, string $customerRef, ?string $componentRef): int;

    public function outcomeApplied(TransactionContext $context, BillingAccountScope $scope, string $intentRef, string $operationRef, string $authority, int $revision, string $state): bool;
}
