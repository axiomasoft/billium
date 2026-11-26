<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Fiscal;

use Billium\Contracts\Data\Money;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\Fiscal\FiscalEvidenceRecord;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalPolicyRecord;
use Billium\Execution\Data\Fiscal\FiscalProjectionRecord;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;

/** Row hydration, deterministic references, and append-only custody writes for fiscal orchestration. */
#[Internal]
final class FiscalStorage
{
    public static function externalId(
        ExecutionScope $scope,
        string $economicEffectRef,
        FiscalReceiptKind $receiptKind,
        int $correctionRevision,
    ): string {
        return 'fext:'.substr(FiscalJson::digest([
            'schema' => 'billium.fiscal.external-id.v1',
            'installationRef' => $scope->installationRef,
            'sellerRef' => $scope->sellerRef,
            'environment' => $scope->environment,
            'economicEffectRef' => $economicEffectRef,
            'receiptKind' => $receiptKind->value,
            'correctionRevision' => $correctionRevision,
        ]), 7, 48);
    }

    public static function intentRef(
        ExecutionScope $scope,
        string $economicEffectRef,
        FiscalReceiptKind $receiptKind,
        int $correctionRevision,
    ): string {
        return 'fint:'.substr(FiscalJson::digest([
            'schema' => 'billium.fiscal.intent-ref.v1',
            'installationRef' => $scope->installationRef,
            'sellerRef' => $scope->sellerRef,
            'environment' => $scope->environment,
            'economicEffectRef' => $economicEffectRef,
            'receiptKind' => $receiptKind->value,
            'correctionRevision' => $correctionRevision,
        ]), 7, 48);
    }

    public static function projectionRef(
        ExecutionScope $scope,
        string $economicEffectRef,
        FiscalReceiptKind $receiptKind,
        int $correctionRevision,
        string $fiscalStatus,
    ): string {
        return 'fproj:'.substr(FiscalJson::digest([
            'schema' => 'billium.fiscal.projection.v1',
            'installationRef' => $scope->installationRef,
            'sellerRef' => $scope->sellerRef,
            'environment' => $scope->environment,
            'economicEffectRef' => $economicEffectRef,
            'receiptKind' => $receiptKind->value,
            'correctionRevision' => $correctionRevision,
            'fiscalStatus' => $fiscalStatus,
        ]), 7, 48);
    }

    /** @param array<string, mixed> $sanitizedPayload */
    public static function appendEvidence(
        Connection $db,
        ExecutionScope $scope,
        FiscalIntentRecord $intent,
        string $evidenceKind,
        ?string $credentialVersionRef,
        string $trustVerdict,
        ?string $externalEventId,
        string $outcomeState,
        array $sanitizedPayload,
        DateTimeImmutable $recordedAt,
    ): void {
        $evidenceRef = 'fev:'.Str::ulid();
        $payloadDigest = FiscalJson::digest($sanitizedPayload);
        $db->table('billium_fiscal_evidence')->insert([
            'installation_ref' => $scope->installationRef,
            'seller_ref' => $scope->sellerRef,
            'environment' => $scope->environment,
            'evidence_ref' => $evidenceRef,
            'fiscal_intent_ref' => $intent->intentRef,
            'evidence_kind' => $evidenceKind,
            'driver_id' => $intent->driverId,
            'driver_contract_version' => $intent->driverContractVersion,
            'merchant_revision_ref' => $intent->merchantRevisionRef,
            'credential_version_ref' => $credentialVersionRef,
            'trust_verdict' => $trustVerdict,
            'external_event_id' => $externalEventId,
            'outcome_state' => $outcomeState,
            'payload_digest' => $payloadDigest,
            'sanitized_payload' => FiscalJson::encodeObject($sanitizedPayload),
            'recorded_at' => FiscalJson::formatDbTimestamp($recordedAt),
        ]);
    }

    public static function hydratePolicy(ExecutionScope $scope, object $row): FiscalPolicyRecord
    {
        $trust = FiscalJson::nullableText($row->callback_trust_strategy ?? null);

        return new FiscalPolicyRecord(
            id: FiscalJson::integer($row->id ?? null),
            scope: $scope,
            executionProfile: FiscalJson::text($row->execution_profile ?? null),
            operationKind: FiscalOperationKind::from(FiscalJson::text($row->operation_kind ?? null)),
            policyVersion: FiscalJson::integer($row->policy_version ?? null),
            receiptRequired: FiscalJson::boolean($row->receipt_required ?? null),
            fiscalRoute: FiscalRoute::from(FiscalJson::text($row->fiscal_route ?? null)),
            driverId: FiscalJson::nullableText($row->driver_id ?? null),
            driverContractVersion: FiscalJson::nullableText($row->driver_contract_version ?? null),
            callbackTrustStrategy: $trust === null ? null : FiscalCallbackTrustStrategy::from($trust),
            policyDigest: FiscalJson::text($row->policy_digest ?? null),
            metadata: FiscalJson::decodeObject($row->metadata ?? '{}'),
            createdAt: FiscalJson::timestamp($row->created_at ?? null),
        );
    }

    public static function hydrateIntent(ExecutionScope $scope, object $row): FiscalIntentRecord
    {
        $currency = FiscalJson::currency($row->currency ?? null, $row->currency_exponent ?? null);

        return new FiscalIntentRecord(
            id: FiscalJson::integer($row->id ?? null),
            intentRef: FiscalJson::text($row->intent_ref ?? null),
            scope: $scope,
            executionProfile: FiscalJson::text($row->execution_profile ?? null),
            economicEffectRef: FiscalJson::text($row->economic_effect_ref ?? null),
            rootOperationRef: FiscalJson::text($row->root_operation_ref ?? null),
            operationRef: FiscalJson::text($row->operation_ref ?? null),
            receiptKind: FiscalReceiptKind::from(FiscalJson::text($row->receipt_kind ?? null)),
            correctionRevision: FiscalJson::integer($row->correction_revision ?? null),
            parentFiscalIntentRef: FiscalJson::nullableText($row->parent_fiscal_intent_ref ?? null),
            externalId: FiscalJson::text($row->external_id ?? null),
            fiscalRoute: FiscalRoute::from(FiscalJson::text($row->fiscal_route ?? null)),
            driverId: FiscalJson::text($row->driver_id ?? null),
            driverContractVersion: FiscalJson::text($row->driver_contract_version ?? null),
            callbackTrustStrategy: FiscalCallbackTrustStrategy::from(FiscalJson::text($row->callback_trust_strategy ?? null)),
            merchantRevisionRef: FiscalJson::text($row->merchant_revision_ref ?? null),
            merchantAccountRef: FiscalJson::text($row->merchant_account_ref ?? null),
            providerEvidenceRef: FiscalJson::text($row->provider_evidence_ref ?? null),
            policyVersion: FiscalJson::integer($row->policy_version ?? null),
            policyDigest: FiscalJson::text($row->policy_digest ?? null),
            amount: new Money(FiscalJson::integer($row->amount_minor ?? null), $currency),
            exceptional: FiscalJson::boolean($row->exceptional ?? null),
            exposureProvenance: FiscalJson::decodeObject($row->exposure_provenance ?? '{}'),
            payloadSnapshot: FiscalJson::decodeObject($row->payload_snapshot ?? '{}'),
            payloadDigest: FiscalJson::text($row->payload_digest ?? null),
            state: FiscalIntentState::from(FiscalJson::text($row->state ?? null)),
            providerReceiptId: FiscalJson::nullableText($row->provider_receipt_id ?? null),
            fiscalDocumentNumber: FiscalJson::nullableText($row->fiscal_document_number ?? null),
            lastErrorCode: FiscalJson::nullableText($row->last_error_code ?? null),
            lookupAttempts: FiscalJson::integer($row->lookup_attempts ?? null),
            nextLookupAt: FiscalJson::nullableTimestamp($row->next_lookup_at ?? null),
            createdAt: FiscalJson::timestamp($row->created_at ?? null),
            updatedAt: FiscalJson::timestamp($row->updated_at ?? null),
        );
    }

    public static function hydrateEvidence(object $row): FiscalEvidenceRecord
    {
        return new FiscalEvidenceRecord(
            id: FiscalJson::integer($row->id ?? null),
            evidenceRef: FiscalJson::text($row->evidence_ref ?? null),
            fiscalIntentRef: FiscalJson::text($row->fiscal_intent_ref ?? null),
            evidenceKind: FiscalJson::text($row->evidence_kind ?? null),
            driverId: FiscalJson::text($row->driver_id ?? null),
            driverContractVersion: FiscalJson::text($row->driver_contract_version ?? null),
            merchantRevisionRef: FiscalJson::text($row->merchant_revision_ref ?? null),
            credentialVersionRef: FiscalJson::nullableText($row->credential_version_ref ?? null),
            trustVerdict: FiscalJson::text($row->trust_verdict ?? null),
            externalEventId: FiscalJson::nullableText($row->external_event_id ?? null),
            outcomeState: FiscalJson::text($row->outcome_state ?? null),
            payloadDigest: FiscalJson::text($row->payload_digest ?? null),
            sanitizedPayload: FiscalJson::decodeObject($row->sanitized_payload ?? '{}'),
            recordedAt: FiscalJson::timestamp($row->recorded_at ?? null),
        );
    }

    public static function hydrateProjection(ExecutionScope $scope, object $row): FiscalProjectionRecord
    {
        $currency = FiscalJson::currency($row->currency ?? null, $row->currency_exponent ?? null);

        return new FiscalProjectionRecord(
            id: FiscalJson::integer($row->id ?? null),
            projectionRef: FiscalJson::text($row->projection_ref ?? null),
            scope: $scope,
            executionProfile: FiscalJson::text($row->execution_profile ?? null),
            sourceAuthority: FiscalJson::text($row->source_authority ?? null),
            economicEffectRef: FiscalJson::text($row->economic_effect_ref ?? null),
            rootOperationRef: FiscalJson::text($row->root_operation_ref ?? null),
            operationRef: FiscalJson::text($row->operation_ref ?? null),
            receiptKind: FiscalReceiptKind::from(FiscalJson::text($row->receipt_kind ?? null)),
            correctionRevision: FiscalJson::integer($row->correction_revision ?? null),
            externalFiscalRef: FiscalJson::nullableText($row->external_fiscal_ref ?? null),
            fiscalStatus: FiscalJson::text($row->fiscal_status ?? null),
            amount: new Money(FiscalJson::integer($row->amount_minor ?? null), $currency),
            exceptional: FiscalJson::boolean($row->exceptional ?? null),
            exposureProvenance: FiscalJson::decodeObject($row->exposure_provenance ?? '{}'),
            sourcePayload: FiscalJson::decodeObject($row->source_payload ?? '{}'),
            sourceDigest: FiscalJson::text($row->source_digest ?? null),
            recordedAt: FiscalJson::timestamp($row->recorded_at ?? null),
        );
    }
}
