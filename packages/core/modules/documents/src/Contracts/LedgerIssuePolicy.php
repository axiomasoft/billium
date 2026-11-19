<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Explicit port connecting documents legal issue to LedgerParticipants::issueInvoice without foreign table writes. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface LedgerIssuePolicy
{
    public function issue(TransactionContext $context, IssueInvoice $command, string $legalDocumentRef): Receipt;
}
