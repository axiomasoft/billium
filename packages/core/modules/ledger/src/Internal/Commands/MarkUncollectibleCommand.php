<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Invoices\MarkUncollectible;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;
use Packman\Attributes\Internal;

/** issued|partially_paid → uncollectible with recorded reason and authorization. */
#[Internal]
final readonly class MarkUncollectibleCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, MarkUncollectible $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
