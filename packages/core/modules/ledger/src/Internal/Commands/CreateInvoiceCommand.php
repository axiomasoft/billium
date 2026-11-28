<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;
use Packman\Attributes\Internal;

/** Draft over unbilled obligations; no event, no number. */
#[Internal]
final readonly class CreateInvoiceCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, CreateInvoice $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
