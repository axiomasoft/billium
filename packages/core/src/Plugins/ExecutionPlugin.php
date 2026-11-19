<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Core\Database\ExecutionSchemaSetProvider;
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
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
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
use Billium\Execution\Laravel\ExecutionCapabilities;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Stateless Packman extension plugin composing Billium Execution schema ownership and capability contracts.
 */
#[Api(since: '1.0')]
final class ExecutionPlugin implements ExtensionPlugin, WithCapabilities, WithSchema
{
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.execution',
            version: '0.1.0',
            requires: ['axiomasoft.billium.platform'],
            optional: ['axiomasoft.billium.ledger'],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            ExecutionStore::class => Cardinality::OptionalSingle->value,
            MerchantRegistry::class => Cardinality::OptionalSingle->value,
            MerchantActionGate::class => Cardinality::OptionalSingle->value,
            ReconciliationCommands::class => Cardinality::OptionalSingle->value,
            RepairCommands::class => Cardinality::OptionalSingle->value,
            OriginalIdentityRecovery::class => Cardinality::OptionalSingle->value,
            RootPaymentCommands::class => Cardinality::OptionalSingle->value,
            AttemptWorker::class => Cardinality::OptionalSingle->value,
            AuthorizationCommands::class => Cardinality::OptionalSingle->value,
            RefundCommands::class => Cardinality::OptionalSingle->value,
            CallbackCustody::class => Cardinality::OptionalSingle->value,
            EvidenceReducer::class => Cardinality::OptionalSingle->value,
            CapacityParticipants::class => Cardinality::OptionalSingle->value,
            OutcomePublication::class => Cardinality::OptionalSingle->value,
            PaymentObservationSink::class => Cardinality::OptionalSingle->value,
            ExecutionQueries::class => Cardinality::OptionalSingle->value,
            ExecutionHistoryQueries::class => Cardinality::OptionalSingle->value,
            ExecutionEvidenceQueries::class => Cardinality::OptionalSingle->value,
            ExecutionReconciliationQueries::class => Cardinality::OptionalSingle->value,
            FiscalOrchestrator::class => Cardinality::OptionalSingle->value,
            FiscalDrivers::class => Cardinality::OptionalSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return ExecutionCapabilities::map();
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'billium_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return [
            'execution_namespace',
            'execution_merchant_revision',
            'execution_capability_snapshot',
            'execution_business_binding',
            'execution_operation',
            'execution_operation_revision',
            'execution_capacity',
            'execution_reservation',
            'execution_attempt',
            'execution_provider_fact',
            'execution_discrepancy',
            'execution_observation_partition',
            'execution_observation_emission',
            'execution_authorization',
            'execution_reconciliation_run',
            'execution_repair_receipt',
            'execution_dispatch_work',
            'execution_provider_evidence',
            'execution_provider_work',
            'execution_merchant_lifecycle_event',
            'execution_merchant_credential_revision',
            'execution_admission_conflict',
            'execution_provider_identity',
            'execution_certified_absence',
            'execution_child_identity',
            'execution_recon_binding',
            'execution_recon_page',
            'execution_recon_item',
            'execution_recon_review',
            'execution_original_replay',
            'execution_original_identity_import',
            'fiscal_policy',
            'fiscal_intent',
            'fiscal_evidence',
            'fiscal_projection',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/execution';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [ExecutionSchemaSetProvider::class];
    }
}
