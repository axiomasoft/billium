<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Data\MarkUncollectible;
use Billium\Ledger\Internal\Repositories\InvoiceRepository;

/** issued|partially_paid → uncollectible with recorded reason and authorization. */
final readonly class MarkUncollectibleCommand
{
    public function __construct(private InvoiceRepository $repository) {}

    public function execute(TransactionContext $context, MarkUncollectible $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
