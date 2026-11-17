<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\DiscardInvoice;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;
use Packman\Attributes\Internal;

/** draft → discarded; the obligations are released. */
#[Internal]
final readonly class DiscardInvoiceCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, DiscardInvoice $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
