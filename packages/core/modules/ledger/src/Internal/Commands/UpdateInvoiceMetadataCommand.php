<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;
use Packman\Attributes\Internal;

/** Allow-list merge; keys outside it are refused by state in the guarded routine (INV-13). */
#[Internal]
final readonly class UpdateInvoiceMetadataCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, UpdateInvoiceMetadata $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
