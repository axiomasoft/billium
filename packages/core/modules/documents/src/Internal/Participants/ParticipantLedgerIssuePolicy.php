<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Participants;

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Documents\Contracts\LedgerIssuePolicy;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ParticipantLedgerIssuePolicy implements LedgerIssuePolicy
{
    public function __construct(
        private OperationRunner $runner,
        private LedgerParticipants $ledger,
    ) {}

    public function issue(TransactionContext $context, IssueInvoice $command, string $legalDocumentRef): Receipt
    {
        $this->runner->assertCurrent($context, 'documents');

        return $this->ledger->issueInvoice($context, $command, $legalDocumentRef);
    }
}
