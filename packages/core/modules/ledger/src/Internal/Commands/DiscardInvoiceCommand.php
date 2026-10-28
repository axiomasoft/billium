<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\DiscardInvoice;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;

/** draft → discarded; the obligations are released. */
final readonly class DiscardInvoiceCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, DiscardInvoice $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
