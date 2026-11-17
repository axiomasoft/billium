<?php

declare(strict_types=1);

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReallocationMode;
use Billium\Ledger\Data\Allocations\ReverseAllocation;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\DiscardInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Invoices\MarkUncollectible;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use Billium\Ledger\Data\Invoices\VoidInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\AmendObligationDuePolicy;
use Billium\Ledger\Data\Obligations\CorrectionBasis;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Data\Settlements\NoEffectProof;
use Billium\Platform\Contracts\EventContracts\EventContractRegistrar;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Contracts\Installation\RootAdmission;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\Idempotency\IdempotencyClaim;
use Billium\Platform\Data\Installation\FinancialSessionView;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Data\Outbox\OutboxEvent;

// Compatibility aliases for Billium Core, Platform, and Ledger symbols organized into domain subfolders.
(static function (): void {
    /** @var array<string, class-string> $aliases */
    $aliases = [
        Billium\Core\Contracts\CallerContext::class => CallerContext::class,
        Billium\Core\Contracts\EventPublisher::class => EventPublisher::class,
        Billium\Core\Contracts\FinancialSessionAdmission::class => FinancialSessionAdmission::class,
        Billium\Core\Contracts\OperationRunner::class => OperationRunner::class,
        Billium\Core\Contracts\TransactionContext::class => TransactionContext::class,
        Billium\Core\Data\Caller::class => Caller::class,
        Billium\Core\Data\FinancialAdmissionToken::class => FinancialAdmissionToken::class,
        Billium\Core\Data\LockRequest::class => LockRequest::class,
        Billium\Core\Data\OperationSpec::class => OperationSpec::class,
        Billium\Core\Data\Receipt::class => Receipt::class,
        Billium\Core\Data\StoredOutcome::class => StoredOutcome::class,
        Billium\Core\Enums\FinancialMode::class => FinancialMode::class,
        Billium\Core\Enums\LockRank::class => LockRank::class,
        Billium\Core\Enums\LockScope::class => LockScope::class,
        Billium\Core\Exceptions\LockOrderViolation::class => LockOrderViolation::class,
        Billium\Core\Exceptions\LockPlanChanged::class => LockPlanChanged::class,
        Billium\Core\Exceptions\ModuleAdmissionRefused::class => ModuleAdmissionRefused::class,
        Billium\Ledger\Data\AdjustObligation::class => AdjustObligation::class,
        Billium\Ledger\Data\Allocate::class => Allocate::class,
        Billium\Ledger\Data\AllocationReceipt::class => AllocationReceipt::class,
        Billium\Ledger\Data\AmendObligationDuePolicy::class => AmendObligationDuePolicy::class,
        Billium\Ledger\Data\BillingAccountScope::class => BillingAccountScope::class,
        Billium\Ledger\Data\CloseSettlementIntentNoEffect::class => CloseSettlementIntentNoEffect::class,
        Billium\Ledger\Data\CorrectionBasis::class => CorrectionBasis::class,
        Billium\Ledger\Data\CreateInvoice::class => CreateInvoice::class,
        Billium\Ledger\Data\CreditNoteLine::class => CreditNoteLine::class,
        Billium\Ledger\Data\CreditNoteMode::class => CreditNoteMode::class,
        Billium\Ledger\Data\DeclareSettlementIntent::class => DeclareSettlementIntent::class,
        Billium\Ledger\Data\DiscardInvoice::class => DiscardInvoice::class,
        Billium\Ledger\Data\InvoiceLineInput::class => InvoiceLineInput::class,
        Billium\Ledger\Data\IssueCreditNote::class => IssueCreditNote::class,
        Billium\Ledger\Data\IssueInvoice::class => IssueInvoice::class,
        Billium\Ledger\Data\LedgerReceipt::class => LedgerReceipt::class,
        Billium\Ledger\Data\MarkUncollectible::class => MarkUncollectible::class,
        Billium\Ledger\Data\NoEffectProof::class => NoEffectProof::class,
        Billium\Ledger\Data\ObligationDuePolicy::class => ObligationDuePolicy::class,
        Billium\Ledger\Data\ReallocatePayment::class => ReallocatePayment::class,
        Billium\Ledger\Data\ReallocationMode::class => ReallocationMode::class,
        Billium\Ledger\Data\RecordCustomerCredit::class => RecordCustomerCredit::class,
        Billium\Ledger\Data\RecordObligation::class => RecordObligation::class,
        Billium\Ledger\Data\ReverseAllocation::class => ReverseAllocation::class,
        Billium\Ledger\Data\UpdateInvoiceMetadata::class => UpdateInvoiceMetadata::class,
        Billium\Ledger\Data\VoidInvoice::class => VoidInvoice::class,
        Billium\Platform\Contracts\BootstrapItemHandler::class => BootstrapItemHandler::class,
        Billium\Platform\Contracts\CommandOutbox::class => CommandOutbox::class,
        Billium\Platform\Contracts\CustodyHandler::class => CustodyHandler::class,
        Billium\Platform\Contracts\EventContractRegistrar::class => EventContractRegistrar::class,
        Billium\Platform\Contracts\EventFeed::class => EventFeed::class,
        Billium\Platform\Contracts\FeedPartitions::class => FeedPartitions::class,
        Billium\Platform\Contracts\FeedRecoveryQueries::class => FeedRecoveryQueries::class,
        Billium\Platform\Contracts\IdempotencyStore::class => IdempotencyStore::class,
        Billium\Platform\Contracts\Installation::class => Installation::class,
        Billium\Platform\Contracts\Outbox::class => Outbox::class,
        Billium\Platform\Contracts\RootAdmission::class => RootAdmission::class,
        Billium\Platform\Contracts\SnapshotSource::class => SnapshotSource::class,
        Billium\Platform\Data\FinancialSessionView::class => FinancialSessionView::class,
        Billium\Platform\Data\IdempotencyClaim::class => IdempotencyClaim::class,
        Billium\Platform\Data\ModuleTransitionRequest::class => ModuleTransitionRequest::class,
        Billium\Platform\Data\OutboxEvent::class => OutboxEvent::class,
        Billium\Platform\Data\PlatformReceipt::class => PlatformReceipt::class,
    ];

    foreach ($aliases as $alias => $target) {
        if (! class_exists($alias, false) && ! interface_exists($alias, false) && ! enum_exists($alias, false)) {
            class_alias($target, $alias);
        }
    }
})();
