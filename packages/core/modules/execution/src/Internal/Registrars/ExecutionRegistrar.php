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
use Billium\Core\Plugins\ExecutionPlugin;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CollectionCancellationParticipant;
use Billium\Execution\Contracts\CollectionExecutionGuard;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Internal\Admission\AdmitRoot;
use Billium\Execution\Internal\Admission\CollectionChecks;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Admission\RootPayments;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Dispatch\OneAttemptWorker;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Recovery\QualifiedAbsence;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Platform\Contracts\Installation\Installation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Schema\Laravel\SchemaExtensions;

#[Internal]
final class ExecutionRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        ExtensionRegistrar::builtIn(ExecutionPlugin::class);
        $app->singletonIf(ExecutionPlugin::class);
        SchemaExtensions::register($app, ExecutionPlugin::class, 'axiomasoft.billium.execution');
        $app->singleton(DatabaseExecutionStore::class, static fn (Application $app): DatabaseExecutionStore => new DatabaseExecutionStore(
            $app->make(DatabaseManager::class),
            $app->make(OperationRunner::class),
            $app->make(CallerContext::class),
            $app->make(BilliumOptions::class),
            $app->make(FinancialSessionAdmission::class),
        ));
        $app->alias(DatabaseExecutionStore::class, ExecutionStore::class);
        $app->singleton(DatabaseMerchantRegistry::class);
        $app->alias(DatabaseMerchantRegistry::class, MerchantRegistry::class);
        $app->alias(DatabaseMerchantRegistry::class, MerchantActionGate::class);
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
            $app->bound(CollectionCancellationParticipant::class) ? $app->make(CollectionCancellationParticipant::class) : null,
        ));
        $app->bind(AttemptWorker::class, OneAttemptWorker::class);
        $app->singleton(DispatchAuthority::class, static fn (Application $app): DispatchAuthority => new DispatchAuthority(
            $app->make(DatabaseManager::class),
            static fn (): OperationRunner => $app->make(OperationRunner::class),
            static fn (): FinancialSessionAdmission => $app->make(FinancialSessionAdmission::class),
            static fn (): FinancialAuthorityEvidence => $app->make(FinancialAuthorityEvidence::class),
            $app->make(CallerContext::class),
            $app->make(Installation::class),
            $app->make(ExecutionStore::class),
            $app->make(WriterAdmission::class),
            $app->make(DispatchEgress::class),
            $app->make(BilliumOptions::class)->connection ?? $app->make(DatabaseManager::class)->getDefaultConnection(),
        ));
    }

    public function boot(Application $app): void {}
}
