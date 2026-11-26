<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Internal\Repositories\LedgerRepository;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DeclareSettlementIntentCommand
{
    public function __construct(private LedgerRepository $repository) {}

    public function execute(TransactionContext $context, DeclareSettlementIntent $command): LedgerReceipt
    {
        return $this->repository->execute($context, $command);
    }
}
