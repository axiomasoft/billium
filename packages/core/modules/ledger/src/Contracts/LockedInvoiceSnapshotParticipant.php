<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Read-only participant reading the live locked invoice totals and per-obligation lines inside an active root transaction.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface LockedInvoiceSnapshotParticipant
{
    /**
     * @return array{currency: string, subtotalMinor: int, taxMinor: int, totalMinor: int, lines: list<array{obligationRef: string, subtotalMinor: int, taxMinor: int, totalMinor: int}>}
     */
    public function lockedInvoiceSnapshot(TransactionContext $context, BillingAccountScope $scope, string $invoiceRef): array;
}
