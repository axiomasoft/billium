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
use Billium\Ledger\Data\Allocate;
use Billium\Ledger\Data\CreateInvoice;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\DiscardInvoice;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\MarkUncollectible;
use Billium\Ledger\Data\ReallocatePayment;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Data\ReverseAllocation;
use Billium\Ledger\Data\UpdateInvoiceMetadata;
use Billium\Ledger\Data\VoidInvoice;
use Billium\Ledger\Internal\Commands\AdjustObligationCommand;
use Billium\Ledger\Internal\Commands\AllocateCommand;
use Billium\Ledger\Internal\Commands\CreateInvoiceCommand;
use Billium\Ledger\Internal\Commands\DeclareSettlementIntentCommand;
use Billium\Ledger\Internal\Commands\DiscardInvoiceCommand;
use Billium\Ledger\Internal\Commands\IssueCreditNoteCommand;
use Billium\Ledger\Internal\Commands\IssueInvoiceCommand;
use Billium\Ledger\Internal\Commands\MarkUncollectibleCommand;
use Billium\Ledger\Internal\Commands\ReallocatePaymentCommand;
use Billium\Ledger\Internal\Commands\RecordCustomerCreditCommand;
use Billium\Ledger\Internal\Commands\RecordObligationCommand;
use Billium\Ledger\Internal\Commands\ReverseAllocationCommand;
use Billium\Ledger\Internal\Commands\UpdateInvoiceMetadataCommand;
use Billium\Ledger\Internal\Commands\VoidInvoiceCommand;
use Billium\Ledger\Internal\Repositories\CommandPayload;
use Billium\Ledger\Internal\Repositories\InvoicePayload;

final readonly class LedgerFacade implements Ledger
{
    public function __construct(
        private OperationRunner $runner,
        private RecordObligationCommand $obligations,
        private AdjustObligationCommand $adjustments,
        private RecordCustomerCreditCommand $credits,
        private DeclareSettlementIntentCommand $intents,
        private CreateInvoiceCommand $invoiceDrafts,
        private UpdateInvoiceMetadataCommand $invoiceMetadata,
        private IssueInvoiceCommand $invoiceIssues,
        private DiscardInvoiceCommand $invoiceDiscards,
        private VoidInvoiceCommand $invoiceVoids,
        private MarkUncollectibleCommand $invoiceUncollectibles,
        private AllocateCommand $allocations,
        private ReverseAllocationCommand $reversals,
        private IssueCreditNoteCommand $creditNotes,
        private ReallocatePaymentCommand $reallocations,
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

    public function createInvoice(CreateInvoice $command): StoredOutcome
    {
        return $this->runner->run($this->spec('create_invoice', $command), fn (TransactionContext $context) => $this->invoiceDrafts->execute($context, $command));
    }

    public function updateInvoiceMetadata(UpdateInvoiceMetadata $command): StoredOutcome
    {
        return $this->runner->run($this->spec('update_invoice_metadata', $command), fn (TransactionContext $context) => $this->invoiceMetadata->execute($context, $command));
    }

    public function issueInvoice(IssueInvoice $command): StoredOutcome
    {
        return $this->runner->run($this->spec('issue_invoice', $command), fn (TransactionContext $context) => $this->invoiceIssues->execute($context, $command));
    }

    public function discardInvoice(DiscardInvoice $command): StoredOutcome
    {
        return $this->runner->run($this->spec('discard_invoice', $command), fn (TransactionContext $context) => $this->invoiceDiscards->execute($context, $command));
    }

    public function voidInvoice(VoidInvoice $command): StoredOutcome
    {
        return $this->runner->run($this->spec('void_invoice', $command), fn (TransactionContext $context) => $this->invoiceVoids->execute($context, $command));
    }

    public function markUncollectible(MarkUncollectible $command): StoredOutcome
    {
        return $this->runner->run($this->spec('mark_uncollectible', $command), fn (TransactionContext $context) => $this->invoiceUncollectibles->execute($context, $command));
    }

    public function allocate(Allocate $command): StoredOutcome
    {
        AllocateCommand::validate($command);
        $spec = $this->allocationSpec('allocate', $command->scope->sellerRef, $command->scope->environment, $command->commandKey, AllocateCommand::digest($command));

        return $this->runner->run($spec, fn (TransactionContext $context) => $this->allocations->execute($context, $command));
    }

    public function reverseAllocation(ReverseAllocation $command): StoredOutcome
    {
        ReverseAllocationCommand::validate($command);
        $spec = $this->allocationSpec('reverse_allocation', $command->scope->sellerRef, $command->scope->environment, $command->commandKey, ReverseAllocationCommand::digest($command));

        return $this->runner->run($spec, fn (TransactionContext $context) => $this->reversals->execute($context, $command));
    }

    public function issueCreditNote(IssueCreditNote $command): StoredOutcome
    {
        IssueCreditNoteCommand::validate($command);
        $spec = $this->allocationSpec('issue_credit_note', $command->scope->sellerRef, $command->scope->environment, $command->commandKey, IssueCreditNoteCommand::digest($command));

        return $this->runner->run($spec, fn (TransactionContext $context) => $this->creditNotes->execute($context, $command));
    }

    public function reallocatePayment(ReallocatePayment $command): StoredOutcome
    {
        ReallocatePaymentCommand::validate($command);
        $spec = $this->allocationSpec('reallocate_payment', $command->sellerRef, $command->environment, $command->commandKey, ReallocatePaymentCommand::digest($command));

        return $this->runner->run($spec, fn (TransactionContext $context) => $this->reallocations->execute($context, $command));
    }

    private function allocationSpec(string $kind, string $seller, string $environment, string $key, string $digest): OperationSpec
    {
        return new OperationSpec('ledger.'.$kind, $seller, $environment, FinancialMode::Active, ModuleOperationClass::NewIntent, ['ledger', 'platform'], $key, $digest, 'ledger.'.$kind);
    }

    private function spec(string $kind, RecordObligation|AdjustObligation|RecordCustomerCredit|DeclareSettlementIntent|CreateInvoice|UpdateInvoiceMetadata|IssueInvoice|DiscardInvoice|VoidInvoice|MarkUncollectible $command): OperationSpec
    {
        if (! (
            $command instanceof RecordObligation || $command instanceof AdjustObligation
            || $command instanceof RecordCustomerCredit || $command instanceof DeclareSettlementIntent
        )) {
            InvoicePayload::validate($command);

            return new OperationSpec('ledger.'.$kind, $command->scope->sellerRef, $command->scope->environment, FinancialMode::Active,
                ModuleOperationClass::NewIntent, ['ledger', 'platform'], $command->commandKey, InvoicePayload::digest($command), 'ledger.'.$kind);
        }
        CommandPayload::validate($command);

        return new OperationSpec('ledger.'.$kind, $command->scope->sellerRef, $command->scope->environment, FinancialMode::Active,
            ModuleOperationClass::NewIntent, ['ledger', 'platform'], $command->commandKey, CommandPayload::digest($command), 'ledger.'.$kind);
    }
}
