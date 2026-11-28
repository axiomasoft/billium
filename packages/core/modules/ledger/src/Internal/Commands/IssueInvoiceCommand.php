<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\Outbox\OutboxEvent;
use DateTimeImmutable;
use Illuminate\Support\Str;
use LogicException;
use Packman\Attributes\Internal;

/**
 * draft → issued with the derived payment status in the same Tx. The public root passes no legal reference;
 * legal document numbering supplies it through LedgerParticipants::issueInvoice in the same root Tx.
 */
#[Internal]
final readonly class IssueInvoiceCommand
{
    public function __construct(private InvoiceRepository $repository, private Outbox $outbox) {}

    public function execute(TransactionContext $context, IssueInvoice $command, ?string $legalDocumentRef = null): LedgerReceipt
    {
        $receipt = $this->repository->execute($context, $command, $legalDocumentRef);
        if (! $receipt->replayed) {
            $body = $receipt->body();
            $revision = $body['revision'] ?? null;
            if (! is_int($revision)) {
                throw new LogicException('Missing invoice revision');
            }
            $this->outbox->record($context, new OutboxEvent((string) Str::ulid(), 'invoice.issued.v1', 'billium.event.v1',
                $command->invoiceRef, $revision, new DateTimeImmutable, [
                    'invoice_ref' => $command->invoiceRef,
                    'billing_account_ref' => $command->scope->billingAccountRef,
                    'total_minor' => $body['total_minor'] ?? null,
                    'currency' => $body['currency'] ?? null,
                    'legal_document_ref' => $legalDocumentRef,
                ]));
        }

        return $receipt;
    }
}
