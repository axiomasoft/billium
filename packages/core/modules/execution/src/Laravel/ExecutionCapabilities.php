<?php

declare(strict_types=1);

namespace Billium\Execution\Laravel;

use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionEvidenceQueries;
use Billium\Execution\Contracts\ExecutionHistoryQueries;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionReconciliationQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\OriginalIdentityRecovery;
use Billium\Execution\Contracts\OutcomePublication;
use Billium\Execution\Contracts\PaymentObservationSink;
use Billium\Execution\Contracts\ReconciliationCommands;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Internal\Admission\RootPayments;
use Billium\Execution\Internal\Callbacks\CallbackIntake;
use Billium\Execution\Internal\Capacity\ExecutionCapacity;
use Billium\Execution\Internal\Capture\AuthorizedPayments;
use Billium\Execution\Internal\Dispatch\OneAttemptWorker;
use Billium\Execution\Internal\Fiscal\DatabaseFiscalOrchestrator;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Publication\OutcomeEmitter;
use Billium\Execution\Internal\Publication\PlatformPaymentObservationSink;
use Billium\Execution\Internal\Queries\DatabaseExecutionEvidence;
use Billium\Execution\Internal\Queries\DatabaseExecutionHistory;
use Billium\Execution\Internal\Queries\DatabaseExecutionQueries;
use Billium\Execution\Internal\Queries\DatabaseExecutionReconciliationQueries;
use Billium\Execution\Internal\Reconciliation\ExecutionReconciliation;
use Billium\Execution\Internal\Recovery\OriginalIdentityRecoveryService;
use Billium\Execution\Internal\Reducer\ProviderReducer;
use Billium\Execution\Internal\Refunds\RefundPayments;
use Billium\Execution\Internal\Repair\ExecutionRepairs;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability bindings declared by the Billium Execution module for Core plugin wiring.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ExecutionCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            ExecutionStore::class => DatabaseExecutionStore::class,
            MerchantRegistry::class => DatabaseMerchantRegistry::class,
            MerchantActionGate::class => DatabaseMerchantRegistry::class,
            ReconciliationCommands::class => ExecutionReconciliation::class,
            RepairCommands::class => ExecutionRepairs::class,
            OriginalIdentityRecovery::class => OriginalIdentityRecoveryService::class,
            RootPaymentCommands::class => RootPayments::class,
            AttemptWorker::class => OneAttemptWorker::class,
            AuthorizationCommands::class => AuthorizedPayments::class,
            RefundCommands::class => RefundPayments::class,
            CallbackCustody::class => CallbackIntake::class,
            EvidenceReducer::class => ProviderReducer::class,
            CapacityParticipants::class => ExecutionCapacity::class,
            OutcomePublication::class => OutcomeEmitter::class,
            PaymentObservationSink::class => PlatformPaymentObservationSink::class,
            ExecutionQueries::class => DatabaseExecutionQueries::class,
            ExecutionHistoryQueries::class => DatabaseExecutionHistory::class,
            ExecutionEvidenceQueries::class => DatabaseExecutionEvidence::class,
            ExecutionReconciliationQueries::class => DatabaseExecutionReconciliationQueries::class,
            FiscalOrchestrator::class => DatabaseFiscalOrchestrator::class,
        ];
    }
}
