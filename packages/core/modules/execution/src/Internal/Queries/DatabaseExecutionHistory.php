<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Queries;

use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\ExecutionHistoryQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\AdmissionConflictAudit;
use Billium\Execution\Data\ChildIntentIdentity;
use Billium\Execution\Data\ExecutionBusinessIdentity;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Data\Idempotency\StoredCommandReceipt;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseExecutionHistory implements ExecutionHistoryQueries
{
    public function __construct(
        private ExecutionReads $reads,
        private ExecutionStore $store,
        private IdempotencyReceiptQueries $receipts,
    ) {}

    public function business(ExecutionScope $scope, string $sourceAuthority, string $sourceContext, string $businessIntentRef): ExecutionBusinessIdentity
    {
        $this->reads->select($scope, $sourceAuthority);
        ExecutionReads::validate($sourceContext, $businessIntentRef);

        return $this->reads->snapshot(function (Connection $db) use ($scope, $sourceAuthority, $sourceContext, $businessIntentRef): ExecutionBusinessIdentity {
            $row = ExecutionReads::table($db, 'business_binding', $scope)
                ->whereRaw("business_identity_digest = public.billium_execution_business_identity_digest(?, ?, ?, ?, 'charge')", [
                    $scope->intentOwnerNamespace, $scope->environment, $sourceContext, $businessIntentRef,
                ])
                ->where('intent_owner_namespace', $scope->intentOwnerNamespace)
                ->where('source_context', $sourceContext)->where('business_intent_ref', $businessIntentRef)
                ->where('operation_kind', 'charge')->where('binding->executionProfile', $sourceAuthority)
                ->first(['operation_ref', 'created_at', 'retention_policy', 'retention_deadline_at']);
            if ($row === null) {
                throw ExecutionReads::unknown();
            }
            $operation = $this->reads->operation($scope, ExecutionCodec::text($row->operation_ref), $sourceAuthority);
            $attempt = $this->store->attemptForOperation($scope, $operation->operationRef);
            $state = $operation->state === 'admitted' && $attempt !== null && in_array($attempt->state, [AttemptState::Dispatching, AttemptState::Pending, AttemptState::Unknown], true)
                ? $attempt->state->value : $operation->state;

            return new ExecutionBusinessIdentity($operation->binding, $operation->kind, $operation->requested,
                $state, $operation->revision, $operation->economicDigest, $attempt?->immutableRequestDigest,
                new DateTimeImmutable(ExecutionCodec::text($row->created_at)), ExecutionCodec::text($row->retention_policy),
                $row->retention_deadline_at === null ? null : new DateTimeImmutable(ExecutionCodec::text($row->retention_deadline_at)),
                PaymentIdentity::reference($scope, $operation->operationRef, 'admission'));
        });
    }

    public function receiptByCommandKey(ExecutionScope $scope, string $sourceAuthority, string $schema, string $sourceContext, string $commandKey): StoredCommandReceipt
    {
        $selected = $this->reads->select($scope, $sourceAuthority);
        ExecutionReads::validate($sourceContext, $commandKey);
        $key = PaymentIdentity::commandKey($scope, self::commandKind($schema), $sourceContext, $commandKey);

        return $this->receipts->byCommandKey($selected, $schema, $key) ?? throw ExecutionReads::unknown();
    }

    public function childBusiness(ExecutionScope $scope, string $sourceAuthority, EffectKind $kind, string $sourceContext, string $businessIntentRef): ChildIntentIdentity
    {
        $this->reads->select($scope, $sourceAuthority);
        ExecutionReads::validate($sourceContext, $businessIntentRef);
        if (! in_array($kind, [EffectKind::Capture, EffectKind::VoidAuthorization, EffectKind::Refund], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Child identity requires a capture, void or refund kind');
        }

        return $this->reads->snapshot(function (Connection $db) use ($scope, $sourceAuthority, $kind, $sourceContext, $businessIntentRef): ChildIntentIdentity {
            $row = ExecutionReads::table($db, 'child_identity', $scope)
                ->whereRaw('business_identity_digest = public.billium_execution_business_identity_digest(?, ?, ?, ?, ?)', [
                    $scope->intentOwnerNamespace, $scope->environment, $sourceContext, $businessIntentRef, $kind->value,
                ])
                ->where('intent_owner_namespace', $scope->intentOwnerNamespace)
                ->where('source_context', $sourceContext)->where('business_intent_ref', $businessIntentRef)
                ->where('operation_kind', $kind->value)->where('binding->executionProfile', $sourceAuthority)->first();
            if ($row === null) {
                throw ExecutionReads::unknown();
            }
            $operation = $this->reads->operation($scope, ExecutionCodec::text($row->operation_ref), $sourceAuthority);

            return new ChildIntentIdentity($operation, $businessIntentRef,
                $row->reason_code === null ? null : ExecutionCodec::text($row->reason_code), $row->category === null ? null : ExecutionCodec::text($row->category),
                ExecutionCodec::text($row->semantic_digest), new DateTimeImmutable(ExecutionCodec::text($row->created_at)), ExecutionCodec::text($row->retention_policy),
                $row->retention_deadline_at === null ? null : new DateTimeImmutable(ExecutionCodec::text($row->retention_deadline_at)),
                ExecutionCodec::text($row->receipt_ref));
        });
    }

    public function admissionConflicts(ExecutionScope $scope, string $sourceAuthority, string $schema, string $sourceContext, string $commandKey, int $limit = 25): array
    {
        $this->reads->select($scope, $sourceAuthority);
        ExecutionReads::validate($sourceContext, $commandKey);
        if ($schema === CancelOperation::SCHEMA || $limit < 1 || $limit > 100) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid admission conflict query');
        }
        $key = PaymentIdentity::commandKey($scope, self::commandKind($schema), $sourceContext, $commandKey);

        return $this->reads->snapshot(static function (Connection $db) use ($scope, $sourceAuthority, $schema, $key, $limit): array {
            $rows = ExecutionReads::table($db, 'admission_conflict', $scope)->where([
                'intent_owner_namespace' => $scope->intentOwnerNamespace,
                'source_authority' => $sourceAuthority,
                'command_kind' => $schema,
                'command_key_digest' => $key,
            ])->orderByDesc('recorded_at')->orderByDesc('audit_ref')->limit($limit)->get();
            $results = [];
            foreach ($rows as $row) {
                // Original refs on new audit rows were checked against this same immutable source binding at write time.
                $results[] = new AdmissionConflictAudit(ExecutionCodec::text($row->audit_ref), ProblemCode::from(ExecutionCodec::text($row->problem_code)),
                    ExecutionCodec::text($row->actor_ref), new DateTimeImmutable(ExecutionCodec::text($row->recorded_at)),
                    ExecutionCodec::text($row->command_key_digest), ExecutionCodec::text($row->semantic_digest),
                    $row->original_operation_ref === null ? null : ExecutionCodec::text($row->original_operation_ref));
            }

            return $results;
        });
    }

    private static function commandKind(string $schema): string
    {
        return match ($schema) {
            ExecutePayment::SCHEMA, AuthorizePayment::SCHEMA => EffectKind::Charge->value,
            CancelOperation::SCHEMA => 'cancel',
            CapturePayment::SCHEMA => EffectKind::Capture->value,
            VoidAuthorization::SCHEMA => EffectKind::VoidAuthorization->value,
            RequestRefund::SCHEMA => EffectKind::Refund->value,
            default => throw new ProblemException(ProblemCode::SchemaUnsupported, 'Unknown execution command receipt schema'),
        };
    }
}
