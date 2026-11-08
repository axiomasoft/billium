<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Data\VoidInvoice;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;

/** issued → void at settled = 0; the obligations are released. */
final readonly class VoidInvoiceCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, VoidInvoice $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
