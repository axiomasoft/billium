<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Registrars;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\Collection\CollectionOriginLookup;
use Billium\Execution\Contracts\Collection\RootIdentityStore;
use Billium\Execution\Contracts\CollectionCancellationParticipant;
use Billium\Execution\Contracts\CollectionExecutionGuard;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionEvidenceQueries;
use Billium\Execution\Contracts\ExecutionHistoryQueries;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\ExecutionReconciliationQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\OriginalIdentityArchive;
use Billium\Execution\Contracts\OriginalIdentityRecovery;
use Billium\Execution\Contracts\OriginalReplayAuthority;
use Billium\Execution\Contracts\OutcomePublication;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\PaymentObservationSink;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\ReconciliationCommands;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Internal\Admission\AdmitRoot;
use Billium\Execution\Internal\Admission\CollectionChecks;
use Billium\Execution\Internal\Admission\CommandInputReplay;
use Billium\Execution\Internal\Admission\ExecutionReadinessGuard;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Admission\RootPayments;
use Billium\Execution\Internal\Authority\AbsenceProofRegistry;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Callbacks\CallbackIntake;
use Billium\Execution\Internal\Capacity\ExecutionCapacity;
use Billium\Execution\Internal\Capture\AuthorizedPayments;
use Billium\Execution\Internal\Custody\CallbackFrame;
use Billium\Execution\Internal\Custody\VerifyDelivery;
use Billium\Execution\Internal\Dispatch\AttemptLeases;
use Billium\Execution\Internal\Dispatch\OneAttemptWorker;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Disputes\InventoryComparison;
use Billium\Execution\Internal\Fiscal\ActualEffectExtractor;
use Billium\Execution\Internal\Fiscal\DatabaseFiscalOrchestrator;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Publication\OutcomeEmitter;
use Billium\Execution\Internal\Publication\PlatformPaymentObservationSink;
use Billium\Execution\Internal\Queries\DatabaseExecutionEvidence;
use Billium\Execution\Internal\Queries\DatabaseExecutionHistory;
use Billium\Execution\Internal\Queries\DatabaseExecutionQueries;
use Billium\Execution\Internal\Queries\DatabaseExecutionReconciliationQueries;
use Billium\Execution\Internal\Reconciliation\ExecutionReconciliation;
use Billium\Execution\Internal\Reconciliation\InventoryItems;
use Billium\Execution\Internal\Reconciliation\ReconciliationJournal;
use Billium\Execution\Internal\Reconciliation\TargetedRecovery;
use Billium\Execution\Internal\Recovery\AttemptJournal;
use Billium\Execution\Internal\Recovery\OriginalIdentityRecoveryService;
use Billium\Execution\Internal\Recovery\OriginalRequestReplay;
use Billium\Execution\Internal\Recovery\QualifiedAbsence;
use Billium\Execution\Internal\Reducer\ProviderReducer;
use Billium\Execution\Internal\Reducer\VerifiedReduction;
use Billium\Execution\Internal\Refunds\RefundPayments;
use Billium\Execution\Internal\Repair\ExecutionRepairs;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Platform\Contracts\Installation\Installation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class ExecutionRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(VerifiedReduction::class, static fn (Application $app): VerifiedReduction => new VerifiedReduction(
            static fn (): VerifyDelivery => $app->make(VerifyDelivery::class),
        ));
        $app->singleton(AbsenceProofRegistry::class, static fn (Application $app): AbsenceProofRegistry => new AbsenceProofRegistry(
            $app->make(TransportBoundary::class),
            static fn (): ?ProviderAbsenceVerifier => $app->bound(ProviderAbsenceVerifier::class) ? $app->make(ProviderAbsenceVerifier::class) : null,
        ));
        $app->singleton(DatabaseExecutionStore::class, static fn (Application $app): DatabaseExecutionStore => new DatabaseExecutionStore(
            $app->make(DatabaseManager::class),
            $app->make(OperationRunner::class),
            $app->make(CallerContext::class),
            $app->make(BilliumOptions::class),
            $app->make(FinancialSessionAdmission::class),
            $app->make(AbsenceProofRegistry::class),
            static fn (): ?OriginalIdentityArchive => $app->bound(OriginalIdentityArchive::class) ? $app->make(OriginalIdentityArchive::class) : null,
            $app->make(VerifiedReduction::class),
        ));
        $app->alias(DatabaseExecutionStore::class, ExecutionStore::class);
        $app->alias(DatabaseExecutionStore::class, RootIdentityStore::class);
        $app->singleton(DatabaseMerchantRegistry::class);
        $app->alias(DatabaseMerchantRegistry::class, MerchantRegistry::class);
        $app->alias(DatabaseMerchantRegistry::class, MerchantActionGate::class);
        $app->bind(ContinuityMerchantCredentials::class, static fn (Application $app): ContinuityMerchantCredentials => new ContinuityMerchantCredentials(
            static fn (): ?MerchantCredentials => $app->bound(MerchantCredentials::class) ? $app->make(MerchantCredentials::class) : null,
            $app->make(DatabaseMerchantRegistry::class), $app->make(DatabaseManager::class), $app->make(BilliumOptions::class),
        ));
        $app->bind(ExecutionReadinessGuard::class, static fn (Application $app): ExecutionReadinessGuard => new ExecutionReadinessGuard(
            $app->make(BilliumOptions::class), $app->make(TransportBoundary::class),
            static fn (): ?ExecutionReadiness => $app->bound(ExecutionReadiness::class) ? $app->make(ExecutionReadiness::class) : null,
        ));
        $drivers = static fn (): PaymentDrivers => $app->bound(PaymentDrivers::class)
            ? $app->make(PaymentDrivers::class)
            : throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No payment drivers are configured');
        $qualifications = static fn (): AdapterQualification => $app->bound(AdapterQualification::class)
            ? $app->make(AdapterQualification::class)
            : throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No adapter qualification verifier is configured');
        $app->bind(VerifyDelivery::class, static fn (Application $app): VerifyDelivery => new VerifyDelivery(
            $app->make(ExecutionStore::class), $app->make(MerchantRegistry::class), $drivers, $qualifications,
            $app->make(TransportBoundary::class), $app->make(CallbackFrame::class),
        ));
        $app->bind(ReconciliationCommands::class, static fn (Application $app): ExecutionReconciliation => new ExecutionReconciliation(
            $app->make(ReconciliationJournal::class), $app->make(ExecutionStore::class), $app->make(CallerContext::class),
            $app->make(MerchantRegistry::class), $drivers, $app->make(ContinuityMerchantCredentials::class),
            $qualifications, $app->make(TransportBoundary::class), $app->make(InventoryItems::class),
            $app->make(TargetedRecovery::class), $app->make(InventoryComparison::class),
        ));
        $app->bind(RepairCommands::class, ExecutionRepairs::class);
        $app->bind(OriginalIdentityRecovery::class, static fn (Application $app): OriginalIdentityRecoveryService => new OriginalIdentityRecoveryService(
            $app->make(OperationRunner::class), $app->make(CallerContext::class), $app->make(FinancialSessionAdmission::class),
            $app->make(Installation::class), $app->make(ExecutionStore::class), $app->make(TransportBoundary::class),
            $app->make(MerchantRegistry::class), $app->make(QualifiedAdapter::class), $app->make(ContinuityMerchantCredentials::class),
            $app->make(AttemptJournal::class), $app->make(EvidenceReducer::class),
            static fn (): ?OriginalIdentityArchive => $app->bound(OriginalIdentityArchive::class) ? $app->make(OriginalIdentityArchive::class) : null,
        ));
        $app->bind(OriginalRequestReplay::class, static fn (Application $app): OriginalRequestReplay => new OriginalRequestReplay(
            $app->make(ExecutionStore::class), $app->make(CallerContext::class), $app->make(MerchantRegistry::class),
            $app->make(ContinuityMerchantCredentials::class), $app->make(QualifiedAdapter::class), $app->make(TransportBoundary::class),
            $app->make(AttemptLeases::class), $app->make(DispatchAuthority::class),
            $app->make(AttemptJournal::class), $app->make(ExecutionReadinessGuard::class),
            $app->make(OperationRunner::class), $app->make(Installation::class), $app->make(CollectionChecks::class),
            $app->make(MerchantActionGate::class), $app->make(DatabaseManager::class),
            static fn (): ?OriginalReplayAuthority => $app->bound(OriginalReplayAuthority::class) ? $app->make(OriginalReplayAuthority::class) : null,
        ));
        $app->bind(CollectionChecks::class, static fn (Application $app): CollectionChecks => new CollectionChecks(
            $app->bound(CollectionExecutionGuard::class) ? $app->make(CollectionExecutionGuard::class) : null,
            $app->make(ModuleRegistry::class),
            $app->make(ModuleStateReader::class),
        ));
        $app->bind(QualifiedAdapter::class, static fn (Application $app): QualifiedAdapter => new QualifiedAdapter(
            $app->make(MerchantRegistry::class),
            static fn (): PaymentDrivers => $app->bound(PaymentDrivers::class)
                ? $app->make(PaymentDrivers::class)
                : throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No payment drivers are configured'),
            static fn (): AdapterQualification => $app->bound(AdapterQualification::class)
                ? $app->make(AdapterQualification::class)
                : throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No adapter qualification verifier is configured'),
            $app->make(TransportBoundary::class),
        ));
        $app->bind(QualifiedAbsence::class, static fn (Application $app): QualifiedAbsence => new QualifiedAbsence(
            $app->make(TransportBoundary::class),
            $app->bound(ProviderAbsenceVerifier::class) ? $app->make(ProviderAbsenceVerifier::class) : null,
        ));
        $app->bind(RootPaymentCommands::class, static fn (Application $app): RootPayments => new RootPayments(
            $app->make(OperationRunner::class),
            $app->make(CallerContext::class),
            $app->make(ExecutionStore::class),
            static fn (): ExecutionOriginResolver => $app->bound(ExecutionOriginResolver::class)
                ? $app->make(ExecutionOriginResolver::class)
                : throw new ProblemException(ProblemCode::RecoveryRequired, 'No original commercial basis resolver is configured'),
            $app->make(MerchantRegistry::class),
            $app->make(QualifiedAdapter::class),
            $app->make(CollectionChecks::class),
            $app->make(AdmitRoot::class),
            $app->make(TransportBoundary::class),
            $app->make(ExecutionReadinessGuard::class),
            $app->make(CommandInputReplay::class),
            $app->bound(CollectionCancellationParticipant::class) ? $app->make(CollectionCancellationParticipant::class) : null,
            $app->bound(CollectionOriginLookup::class) ? $app->make(CollectionOriginLookup::class) : null,
        ));
        $app->bind(AttemptWorker::class, OneAttemptWorker::class);
        $app->bind(AuthorizationCommands::class, AuthorizedPayments::class);
        $app->bind(RefundCommands::class, RefundPayments::class);
        $app->bind(CallbackCustody::class, CallbackIntake::class);
        $app->bind(EvidenceReducer::class, ProviderReducer::class);
        $app->bind(CapacityParticipants::class, ExecutionCapacity::class);
        $app->bind(OutcomePublication::class, OutcomeEmitter::class);
        $app->bind(PaymentObservationSink::class, PlatformPaymentObservationSink::class);
        $app->bind(ExecutionQueries::class, DatabaseExecutionQueries::class);
        $app->bind(ExecutionHistoryQueries::class, DatabaseExecutionHistory::class);
        $app->bind(ExecutionEvidenceQueries::class, DatabaseExecutionEvidence::class);
        $app->bind(ExecutionReconciliationQueries::class, DatabaseExecutionReconciliationQueries::class);
        $fiscalDrivers = static fn (): FiscalDrivers => $app->bound(FiscalDrivers::class)
            ? $app->make(FiscalDrivers::class)
            : throw new ProblemException(ProblemCode::CapabilityUnsupported, 'No fiscal drivers are configured');
        $app->bind(ActualEffectExtractor::class, static fn (Application $app): ActualEffectExtractor => new ActualEffectExtractor(
            $app->make(DatabaseManager::class),
            $app->make(BilliumOptions::class),
        ));
        $app->bind(DatabaseFiscalOrchestrator::class, static fn (Application $app): DatabaseFiscalOrchestrator => new DatabaseFiscalOrchestrator(
            $app->make(DatabaseManager::class),
            $app->make(OperationRunner::class),
            $app->make(CallerContext::class),
            $app->make(Installation::class),
            $app->make(ExecutionStore::class),
            $app->make(ContinuityMerchantCredentials::class),
            $app->make(TransportBoundary::class),
            $fiscalDrivers,
            $app->make(ActualEffectExtractor::class),
            $app->make(BilliumOptions::class),
        ));
        $app->alias(DatabaseFiscalOrchestrator::class, FiscalOrchestrator::class);
        $app->singleton(DispatchAuthority::class, static fn (Application $app): DispatchAuthority => new DispatchAuthority(
            $app->make(DatabaseManager::class),
            static fn (): OperationRunner => $app->make(OperationRunner::class),
            static fn (): FinancialSessionAdmission => $app->make(FinancialSessionAdmission::class),
            static fn (): FinancialAuthorityEvidence => $app->make(FinancialAuthorityEvidence::class),
            $app->make(CallerContext::class),
            $app->make(Installation::class),
            $app->make(ExecutionStore::class),
            static fn (): WriterAdmission => $app->bound(WriterAdmission::class) ? $app->make(WriterAdmission::class) : throw new ProblemException(ProblemCode::RecoveryRequired, 'WriterAdmission host verification is not configured'),
            static fn (): DispatchEgress => $app->bound(DispatchEgress::class) ? $app->make(DispatchEgress::class) : throw new ProblemException(ProblemCode::RecoveryRequired, 'DispatchEgress host fencing is not configured'),
            $app->make(BilliumOptions::class)->connection ?? $app->make(DatabaseManager::class)->getDefaultConnection(),
        ));
    }

    #[Override]
    public function boot(Application $app): void {}
}
