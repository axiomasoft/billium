<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Data\Currency;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Enums\EffectKind;
use LogicException;
use Packman\Attributes\Internal;

/** Merchant revisions and the capability snapshots they bind; written only from administrative roots. */
#[Internal]
final readonly class MerchantRevisionStorage
{
    public function __construct(private ExecutionTables $tables) {}

    public function merchantRevision(ExecutionScope $executionScope, string $revisionRef, ?TransactionContext $context = null): MerchantRevision
    {
        $r = $this->tables->query('merchant_revision', $executionScope, $context)->where('revision_ref', $revisionRef)->first() ?? throw new LogicException('Historical merchant revision unavailable');

        return ExecutionRecords::merchantRevision($executionScope, $r);
    }

    public function appendMerchantRevision(TransactionContext $transactionContext, MerchantRevision $merchantRevision, CapabilitySnapshot $capabilitySnapshot): void
    {
        $this->tables->database($transactionContext);
        $this->tables->assertScope($merchantRevision->scope, $transactionContext);
        if ($transactionContext->spec()->mode !== FinancialMode::Administrative) {
            throw new LogicException('Merchant configuration requires administrative root');
        }
        if ([$merchantRevision->merchantAccountRef, $merchantRevision->driverId, $merchantRevision->driverContractVersion, $merchantRevision->capabilitySnapshotRef, $merchantRevision->capabilityDigest] !== [$capabilitySnapshot->merchantAccountRef, $capabilitySnapshot->driverId, $capabilitySnapshot->driverContractVersion, $capabilitySnapshot->snapshotRef, $capabilitySnapshot->digest]) {
            ExecutionTables::refuse('Merchant capability binding mismatch');
        }
        foreach ($merchantRevision->currencyCodes as $currency) {
            Currency::of($currency);
            if (! in_array($currency, $capabilitySnapshot->currencies, true)) {
                ExecutionTables::refuse('Merchant currency outside capability');
            }
        }
        $this->persistMerchant($transactionContext, $merchantRevision, $capabilitySnapshot);

    }

    public function capability(ExecutionScope $scope, string $ref, ?TransactionContext $context = null): CapabilitySnapshot
    {
        $r = $this->tables->query('capability_snapshot', $scope, $context)->where('snapshot_ref', $ref)->first() ?? throw new LogicException('Capability snapshot absent');

        return ExecutionCodec::decode(CapabilitySnapshot::class, ExecutionRows::text($r->body));
    }

    private function persistMerchant(TransactionContext $context, MerchantRevision $m, CapabilitySnapshot $c): void
    {
        $from = $m->policy->validFrom;
        $until = $m->policy->validUntil;
        $predecessor = $m->policy->predecessorRef;
        $profile = $m->policy->apiProfileVersion;
        $route = $m->policy->webhookRouteRef;
        if ($until !== null && $until <= $from) {
            ExecutionTables::refuse('Invalid merchant effective interval');
        }
        if ($predecessor !== null) {
            $this->merchantRevision($m->scope, $predecessor, $context);
        }
        $inserted = $this->tables->database($context)->table('billium_execution_capability_snapshot')->insertOrIgnore(ExecutionTables::scope($m->scope) + ['snapshot_ref' => $c->snapshotRef, 'merchant_account_ref' => $c->merchantAccountRef, 'driver_id' => $c->driverId, 'driver_contract_version' => $c->driverContractVersion, 'capability_version' => $c->capabilityVersion, 'evidence_mode' => $c->evidenceMode->value, 'body' => ExecutionCodec::encode($c), 'digest' => $c->digest, 'qualification_mode' => $c->qualificationMode->value, 'qualification_evidence_ref' => $c->qualificationEvidenceRef, 'created_at' => $m->createdAt, 'supported_effects' => ExecutionRows::textArray(array_map(static fn (EffectKind $e): string => $e->value, $c->supportedEffects)), 'currencies' => ExecutionRows::textArray($c->currencies), 'capture_mode' => $c->details->captureMode->value, 'partial_refund' => $c->partialRefund, 'multiple_refund' => $c->details->multipleRefund, 'lookup_by_provider_id' => $c->details->lookupByProviderId, 'lookup_by_reference' => $c->lookupByReference, 'inventory_complete' => $c->inventoryComplete, 'absence_certification' => $c->absenceCertification, 'idempotency_retention_seconds' => $c->details->idempotencyRetentionSeconds, 'lookup_retention_seconds' => $c->details->lookupRetentionSeconds]);
        if ($inserted === 0 && ExecutionCodec::differs($this->capability($m->scope, $c->snapshotRef, $context), $c)) {
            ExecutionTables::refuse('Merchant rotation cannot change the original capability snapshot');
        }
        $this->tables->database($context)->table('billium_execution_merchant_revision')->insert(ExecutionTables::scope($m->scope) + ['revision_ref' => $m->revisionRef, 'merchant_account_ref' => $m->merchantAccountRef, 'provider_id' => $m->providerId, 'driver_id' => $m->driverId, 'driver_contract_version' => $m->driverContractVersion, 'capability_snapshot_ref' => $m->capabilitySnapshotRef, 'capability_digest' => $m->capabilityDigest, 'credential_ref' => $m->credentialRef->locator, 'credential_version_ref' => $m->credentialVersionRef, 'currency_codes' => ExecutionCodec::encode($m->currencyCodes), 'digest' => $m->digest, 'created_at' => $m->createdAt, 'valid_from' => $from, 'valid_until' => $until, 'predecessor_ref' => $predecessor, 'api_profile_version' => $profile, 'webhook_route_ref' => $route, 'continuity_evidence_ref' => $m->policy->continuityEvidenceRef, 'settlement_account_ref' => $m->policy->settlementAccountRef, 'mandate_namespace_ref' => $m->policy->mandateNamespaceRef]);
    }
}
