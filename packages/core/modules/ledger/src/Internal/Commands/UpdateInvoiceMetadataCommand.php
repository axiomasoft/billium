<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Data\UpdateInvoiceMetadata;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;

/** Allow-list merge; keys outside it are refused by state in the guarded routine (INV-13). */
final readonly class UpdateInvoiceMetadataCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, UpdateInvoiceMetadata $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
