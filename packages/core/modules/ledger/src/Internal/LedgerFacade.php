<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal;

use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Data\StoredOutcome;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Internal\Commands\AdjustObligationCommand;
use Billium\Ledger\Internal\Commands\DeclareSettlementIntentCommand;
use Billium\Ledger\Internal\Commands\RecordCustomerCreditCommand;
use Billium\Ledger\Internal\Commands\RecordObligationCommand;
use Billium\Ledger\Internal\Repositories\CommandPayload;

final readonly class LedgerFacade implements Ledger
{
    public function __construct(
        private OperationRunner $runner,
        private RecordObligationCommand $obligations,
        private AdjustObligationCommand $adjustments,
        private RecordCustomerCreditCommand $credits,
        private DeclareSettlementIntentCommand $intents,
    ) {}

    public function recordObligation(RecordObligation $command): StoredOutcome
    {
        return $this->runner->run($this->spec('record_obligation', $command), fn (TransactionContext $context) => $this->obligations->execute($context, $command));
    }

    public function adjustObligation(AdjustObligation $command): StoredOutcome
    {
        return $this->runner->run($this->spec('adjust_obligation', $command), fn (TransactionContext $context) => $this->adjustments->execute($context, $command));
    }

    public function recordCustomerCredit(RecordCustomerCredit $command): StoredOutcome
    {
        return $this->runner->run($this->spec('record_customer_credit', $command), fn (TransactionContext $context) => $this->credits->execute($context, $command));
    }

    public function declareSettlementIntent(DeclareSettlementIntent $command): StoredOutcome
    {
        return $this->runner->run($this->spec('declare_settlement_intent', $command), fn (TransactionContext $context) => $this->intents->execute($context, $command));
    }

    private function spec(string $kind, RecordObligation|AdjustObligation|RecordCustomerCredit|DeclareSettlementIntent $command): OperationSpec
    {
        CommandPayload::validate($command);

        return new OperationSpec('ledger.'.$kind, $command->scope->sellerRef, $command->scope->environment, FinancialMode::Active,
            ModuleOperationClass::NewIntent, ['ledger', 'platform'], $command->commandKey, CommandPayload::digest($command), 'ledger.'.$kind);
    }
}
