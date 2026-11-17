<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Queries;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\ExecutionEvidenceQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\ExecutionProviderWorkStatus;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseExecutionEvidence implements ExecutionEvidenceQueries
{
    public function __construct(private ExecutionReads $reads, private ExecutionStore $store) {}

    public function material(ExecutionScope $scope, string $sourceAuthority, string $operationRef, ?int $revision = null): ?MaterialSnapshot
    {
        if ($revision !== null && $revision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Provider material revision must be positive');
        }

        return $this->reads->snapshot(function (Connection $db) use ($scope, $sourceAuthority, $operationRef, $revision): ?MaterialSnapshot {
            $operation = $this->reads->operation($scope, $operationRef, $sourceAuthority);
            if ($revision !== null) {
                $stored = ExecutionReads::table($db, 'operation_revision', $scope)->where('operation_ref', $operation->operationRef)
                    ->where('revision', $revision)->whereNotNull('observed->normalizedStatus')->value('observed');
            } else {
                // Accepted observations may advance without producing a material state revision or any outbox item.
                $attempt = $this->store->attemptForOperation($scope, $operationRef);
                if ($attempt === null) {
                    return null;
                }
                $stored = self::facts($db, $operation, $attempt)->orderByDesc('created_at')->orderByDesc('id')->value('normalized_snapshot');
            }

            return $stored === null ? null : ExecutionCodec::decode(MaterialSnapshot::class, ExecutionCodec::text($stored));
        });
    }

    public function work(ExecutionScope $scope, string $sourceAuthority, string $operationRef, string $workRef): ?ExecutionProviderWorkStatus
    {
        ExecutionReads::validate($workRef);

        return $this->reads->snapshot(function (Connection $db) use ($scope, $sourceAuthority, $operationRef, $workRef): ?ExecutionProviderWorkStatus {
            $operation = $this->reads->operation($scope, $operationRef, $sourceAuthority);
            $attempt = $this->store->attemptForOperation($scope, $operationRef);
            if ($attempt === null) {
                return null;
            }
            $work = ExecutionReads::table($db, 'provider_work', $scope)->where('work_ref', $workRef)->first();
            if ($work === null || ($work->operation_hint !== null && $work->operation_hint !== $operationRef)) {
                return null;
            }
            $evidenceRef = ExecutionCodec::text($work->evidence_ref);
            $merchant = $this->store->merchantRevision($scope, $operation->binding->merchantRevisionRef);
            $evidence = ExecutionReads::table($db, 'provider_evidence', $scope)->where('evidence_ref', $evidenceRef)
                ->where('intent_owner_namespace', $scope->intentOwnerNamespace)->where('provider_id', $merchant->providerId)
                ->where('merchant_account_ref', $operation->binding->merchantAccountRef)
                ->first(['provider_event_id', 'payload_digest', 'signature_verdict', 'handler_contract_version', 'received_at']);
            if ($evidence === null || (! self::facts($db, $operation, $attempt)->whereJsonContains('evidence_refs', $evidenceRef)->exists()
                && ! self::transportLink($db, $operation, $attempt, $merchant->providerId, $evidenceRef))) {
                return null;
            }

            return new ExecutionProviderWorkStatus($scope, $sourceAuthority, $operationRef, $workRef, $evidenceRef, $merchant->providerId,
                $operation->binding->merchantAccountRef, self::nullableText($evidence->provider_event_id), ExecutionCodec::text($evidence->payload_digest),
                ExecutionCodec::text($evidence->signature_verdict), new DateTimeImmutable(ExecutionCodec::text($evidence->received_at)),
                ExecutionCodec::text($evidence->handler_contract_version), ExecutionCodec::text($work->handler_contract_version), ExecutionCodec::text($work->state),
                self::integer($work->attempt_count), self::integer($work->lease_fence), self::date($work->lease_expires_at),
                new DateTimeImmutable(ExecutionCodec::text($work->due_at)), self::nullableText($work->last_error_code), self::date($work->last_error_at),
                self::nullableText($work->result_ref));
        });
    }

    private static function facts(Connection $db, OperationRecord $operation, AttemptRecord $attempt): Builder
    {
        return ExecutionReads::table($db, 'provider_fact', $operation->binding->scope)->where('operation_ref', $operation->operationRef)
            ->where('evidence_mode', ProviderEvidenceMode::CumulativeSnapshot->value)
            ->where('normalized_snapshot->bindingDigest', ExecutionCodec::bindingDigest($operation->binding))
            ->where('normalized_snapshot->providerReference', $attempt->providerReference);
    }

    private static function transportLink(Connection $db, OperationRecord $operation, AttemptRecord $attempt, string $providerId, string $evidenceRef): bool
    {
        $scope = $operation->binding->scope;
        $finishRef = ExecutionReads::table($db, 'attempt', $scope)->where('attempt_ref', $attempt->attemptRef)->value('finish_evidence_ref');
        $refs = is_string($finishRef) && $finishRef !== $evidenceRef ? [$evidenceRef, $finishRef] : [$evidenceRef];

        return ExecutionReads::table($db, 'provider_evidence', $scope)->whereIn('evidence_ref', $refs)
            ->where('intent_owner_namespace', $scope->intentOwnerNamespace)->where('provider_id', $providerId)
            ->where('merchant_account_ref', $operation->binding->merchantAccountRef)
            ->where('handler_contract_version', 'execution.transport.v1')->whereIn('signature_verdict', ['local_transport', 'verified_no_effect'])
            ->where('sanitized_payload->schema', 'billium.execution.transport-observation.v1')
            ->where('sanitized_payload->attemptRef', $attempt->attemptRef)->where('sanitized_payload->operationRef', $operation->operationRef)
            ->where('sanitized_payload->providerReference', $attempt->providerReference)
            ->where(static fn (Builder $q): Builder => $q->where('evidence_ref', $evidenceRef)
                ->orWhere('sanitized_payload->retainedProviderEvidenceRef', $evidenceRef))->exists();
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint');
    }

    private static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : ExecutionCodec::text($value);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable(ExecutionCodec::text($value));
    }
}
