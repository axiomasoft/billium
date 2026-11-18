<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\AuthorizationRecord;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\NamespaceRecord;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ReservationState;
use Packman\Attributes\Internal;
use stdClass;

/** Hydrates execution records from their stored rows; the single place that knows each column layout. */
#[Internal]
final class ExecutionRecords
{
    public static function operation(stdClass $r): OperationRecord
    {
        return new OperationRecord(ExecutionRows::integer($r->id), ExecutionRows::text($r->operation_ref), ExecutionRows::text($r->root_operation_ref),
            EffectKind::from(ExecutionRows::text($r->kind)), ExecutionCodec::decode(FrozenBinding::class, ExecutionRows::text($r->binding)),
            new Money(ExecutionRows::integer($r->requested_minor), Currency::of(ExecutionRows::text($r->currency))), ExecutionRows::integer($r->revision),
            ExecutionRows::text($r->state), ExecutionRows::text($r->economic_digest), ExecutionRows::nullableText($r->authorization_ref), ExecutionRows::date($r->created_at));
    }

    public static function attempt(stdClass $r): AttemptRecord
    {
        return new AttemptRecord(ExecutionRows::integer($r->id), ExecutionRows::text($r->attempt_ref), ExecutionRows::text($r->operation_ref),
            ExecutionRows::text($r->provider_reference), AttemptState::from(ExecutionRows::text($r->state)), ExecutionRows::integer($r->lease_fence),
            ExecutionRows::nullableText($r->lease_owner), ExecutionRows::nullableDate($r->lease_expires_at), ExecutionRows::nullableInteger($r->session_epoch),
            ExecutionRows::nullableText($r->owner_session_ref), ExecutionRows::nullableText($r->dispatch_ticket_ref), ExecutionRows::text($r->request_digest),
            ExecutionRows::nullableDate($r->started_at), ExecutionRows::nullableDate($r->request_deadline_at), ExecutionRows::nullableText($r->collection_dispatch_permit_ref));
    }

    public static function capacity(stdClass $r): CapacitySnapshot
    {
        $currency = Currency::of(ExecutionRows::text($r->currency));
        $money = static fn (mixed $minor): Money => new Money(ExecutionRows::integer($minor), $currency);

        return new CapacitySnapshot(ExecutionRows::text($r->root_operation_ref), ExecutionRows::integer($r->version), $money($r->authorized_limit_minor),
            $money($r->confirmed_capture_minor), $money($r->confirmed_refund_minor), $money($r->released_authorization_minor),
            $money($r->held_capture_minor), $money($r->held_void_minor), $money($r->held_refund_minor), (bool) $r->financial_hold);
    }

    public static function reservation(stdClass $r): Reservation
    {
        $currency = Currency::of(ExecutionRows::text($r->currency));

        return new Reservation(ExecutionRows::text($r->reservation_ref), ExecutionRows::text($r->root_operation_ref), ExecutionRows::text($r->operation_ref),
            EffectKind::from(ExecutionRows::text($r->kind)), new Money(ExecutionRows::integer($r->requested_minor), $currency),
            ReservationState::from(ExecutionRows::text($r->state)), new Money(ExecutionRows::integer($r->consumed_minor), $currency), ExecutionRows::nullableText($r->release_evidence_ref));
    }

    public static function authorization(stdClass $r): AuthorizationRecord
    {
        $currency = Currency::of(ExecutionRows::text($r->currency));

        return new AuthorizationRecord(ExecutionRows::text($r->authorization_ref), ExecutionRows::text($r->root_operation_ref),
            new Money(ExecutionRows::integer($r->authorized_minor), $currency), new Money(ExecutionRows::integer($r->released_minor), $currency),
            ExecutionRows::nullableDate($r->provider_expires_at), ExecutionRows::nullableText($r->expiry_evidence_ref), ExecutionRows::text($r->state),
            ExecutionRows::integer($r->revision), ExecutionRows::nullableText($r->release_evidence_ref));
    }

    public static function fact(stdClass $r): ProviderFact
    {
        return new ProviderFact(ExecutionRows::text($r->fact_ref), ExecutionRows::text($r->operation_ref), ExecutionRows::text($r->provider_identity_scope),
            ExecutionRows::text($r->fact_identity), ExecutionRows::text($r->material_digest), ProviderEvidenceMode::from(ExecutionRows::text($r->evidence_mode)),
            ExecutionCodec::decode(MaterialSnapshot::class, ExecutionRows::text($r->normalized_snapshot)), ExecutionRows::list(ExecutionRows::text($r->evidence_refs)));
    }

    public static function evidence(ExecutionScope $scope, stdClass $r): ProviderEvidence
    {
        return new ProviderEvidence(ExecutionRows::text($r->evidence_ref), $scope, ExecutionRows::text($r->provider_id), ExecutionRows::text($r->merchant_account_ref),
            ExecutionRows::nullableText($r->provider_event_id), ExecutionRows::text($r->payload_digest), ExecutionRows::text($r->signature_verdict),
            ExecutionRows::text($r->handler_contract_version), ExecutionRows::date($r->received_at), ExecutionCodec::map(ExecutionRows::text($r->sanitized_payload)),
            ExecutionRows::nullableText($r->secure_payload_ref));
    }

    public static function merchantRevision(ExecutionScope $scope, stdClass $r): MerchantRevision
    {
        return new MerchantRevision(ExecutionRows::text($r->revision_ref), $scope, ExecutionRows::text($r->merchant_account_ref), ExecutionRows::text($r->provider_id),
            ExecutionRows::text($r->driver_id), ExecutionRows::text($r->driver_contract_version), ExecutionRows::text($r->capability_snapshot_ref),
            ExecutionRows::text($r->capability_digest), new SecretRef(ExecutionRows::text($r->credential_ref)), ExecutionRows::text($r->credential_version_ref),
            ExecutionRows::list(ExecutionRows::text($r->currency_codes)), ExecutionRows::text($r->digest), ExecutionRows::date($r->created_at),
            new MerchantRevisionPolicy(ExecutionRows::date($r->valid_from), ExecutionRows::nullableDate($r->valid_until), ExecutionRows::nullableText($r->predecessor_ref),
                ExecutionRows::text($r->api_profile_version), ExecutionRows::text($r->webhook_route_ref), ExecutionRows::text($r->continuity_evidence_ref),
                ExecutionRows::text($r->settlement_account_ref), ExecutionRows::text($r->mandate_namespace_ref)));
    }

    public static function namespace(ExecutionScope $scope, ?stdClass $r): NamespaceRecord
    {
        if ($r === null) {
            return new NamespaceRecord($scope, NamespaceState::RecoveryRequired, 'execution.intent.v1', null, null, 0);
        }

        return new NamespaceRecord($scope, NamespaceState::from(ExecutionRows::text($r->state)), ExecutionRows::text($r->canonicalization_version),
            ExecutionRows::nullableDate($r->history_from), ExecutionRows::nullableText($r->completeness_receipt_ref), ExecutionRows::integer($r->generation));
    }
}
