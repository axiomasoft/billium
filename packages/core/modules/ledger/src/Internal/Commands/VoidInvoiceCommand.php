<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\VoidInvoice;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;
use Packman\Attributes\Internal;

/** issued → void at settled = 0; the obligations are released. */
#[Internal]
final readonly class VoidInvoiceCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, VoidInvoice $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
