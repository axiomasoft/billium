<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\CreateInvoice;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;

/** Draft over unbilled obligations; no event, no number (D49). */
final readonly class CreateInvoiceCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, CreateInvoice $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
