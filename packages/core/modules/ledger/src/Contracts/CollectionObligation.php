<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Ledger-owned collection eligibility under the obligation lock (D35, D60, D63). */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CollectionObligation
{
    /** When dueAsOf is supplied, check the current authoritative due policy under the same obligation lock. */
    public function lockCollectibleMinor(TransactionContext $context, BillingAccountScope $scope, string $obligationRef, string $intentRef, string $currency, string $customerRef, ?string $componentRef, ?DateTimeImmutable $dueAsOf = null): int;

    public function lockOutstandingMinor(TransactionContext $context, BillingAccountScope $scope, string $obligationRef, string $intentRef, string $currency, string $customerRef): int;

    public function outcomeApplied(TransactionContext $context, BillingAccountScope $scope, string $intentRef, string $operationRef, string $authority, int $revision, string $state): bool;
}
