<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Internal\Repositories\LedgerRepository;

final readonly class RecordCustomerCreditCommand
{
    public function __construct(private LedgerRepository $repository) {}

    public function execute(TransactionContext $context, RecordCustomerCredit $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
