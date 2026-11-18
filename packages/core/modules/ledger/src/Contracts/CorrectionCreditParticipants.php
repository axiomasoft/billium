<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\CreditNoteGroupReceipt;
use Billium\Ledger\Data\Invoices\IssueCreditNoteGroup;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CorrectionCreditParticipants
{
    /** Issues one credit note per invoice of the group inside the caller's root Tx; exact replay returns the same refs. */
    public function issue(TransactionContext $context, IssueCreditNoteGroup $group): CreditNoteGroupReceipt;
}
