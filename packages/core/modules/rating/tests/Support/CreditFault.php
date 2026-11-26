<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Support;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Data\Invoices\CreditNoteGroupReceipt;
use Billium\Ledger\Data\Invoices\IssueCreditNoteGroup;
use RuntimeException;

/** Issues the credit through ledger, then fails the root so the test can prove every credit note rolls back. */
final readonly class CreditFault implements CorrectionCreditParticipants
{
    public function __construct(private CorrectionCreditParticipants $inner, private string $message) {}

    public function issue(TransactionContext $context, IssueCreditNoteGroup $group): CreditNoteGroupReceipt
    {
        $this->inner->issue(context: $context, group: $group);

        throw new RuntimeException($this->message);
    }
}
