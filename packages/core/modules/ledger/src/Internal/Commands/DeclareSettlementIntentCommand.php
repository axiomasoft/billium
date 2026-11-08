<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\TransactionContext;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Internal\Repositories\LedgerRepository;

final readonly class DeclareSettlementIntentCommand
{
    public function __construct(private LedgerRepository $repository) {}

    public function execute(TransactionContext $context, DeclareSettlementIntent $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
