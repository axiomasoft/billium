<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ProviderWorkInput;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Raw provider evidence intake and the provider work queue derived from it; usable from raw-custody roots as well as financial ones. */
#[Internal]
final readonly class ProviderCustodyStorage
{
    public function __construct(private ExecutionTables $tables) {}

    public function appendEvidence(TransactionContext $transactionContext, ProviderEvidence $providerEvidence): string
    {
        $this->tables->custody($transactionContext);
        $this->tables->assertScope($providerEvidence->scope, $transactionContext);
        $payload = ExecutionCodec::encode($providerEvidence->sanitizedPayload);
        if (strlen($payload) > 65536 || preg_match('/"(?:pan|cvc|cvv|password|secret|accessToken|refreshToken|providerToken)"\s*:/i', $payload) === 1) {
            ExecutionTables::refuse('Evidence payload is not sanitized/bounded');
        }
        $db = $this->tables->database($transactionContext);
        $scope = ExecutionTables::scope($providerEvidence->scope);
        $fields = ['intent_owner_namespace' => $providerEvidence->scope->intentOwnerNamespace, 'provider_id' => $providerEvidence->providerId,
            'merchant_account_ref' => $providerEvidence->merchantAccountRef, 'provider_event_id' => $providerEvidence->providerEventId,
            'payload_digest' => $providerEvidence->payloadDigest, 'signature_verdict' => $providerEvidence->signatureVerdict,
            'handler_contract_version' => $providerEvidence->handlerContractVersion, 'secure_payload_ref' => $providerEvidence->securePayloadRef];
        $delivery = $scope + $fields + ['received_at' => $providerEvidence->receivedAt->format(ExecutionRows::TIMESTAMP), 'sanitized_payload' => $payload, 'evidence_ref' => $providerEvidence->evidenceRef];
        $rows = $db->table('billium_execution_provider_evidence')->where($scope);
        $conflict = false;
        if ($providerEvidence->providerEventId !== null && $providerEvidence->signatureVerdict !== 'unverified') {
            // Serialize first deliveries with different receipt references for the same provider event.
            $key = ['schema' => 'billium.execution.evidence-event.v1', ...$scope, 'provider' => $providerEvidence->providerId,
                'account' => $providerEvidence->merchantAccountRef, 'event' => $providerEvidence->providerEventId];
            $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [(new Codec)->canonicalize($key)]);
            $event = (clone $rows)->where('provider_id', $providerEvidence->providerId)->where('merchant_account_ref', $providerEvidence->merchantAccountRef)
                ->where('provider_event_id', $providerEvidence->providerEventId)->whereNotIn('signature_verdict', ['unverified', 'quarantined_conflict'])->orderBy('id')->first();
            $conflict = $event !== null && ! $this->sameDelivery($event, $fields, $payload);
        }
        if (! $conflict) {
            if ($db->table('billium_execution_provider_evidence')->insertOrIgnore($delivery) === 1) {
                return $providerEvidence->evidenceRef;
            }
            $existing = $rows->where('evidence_ref', $providerEvidence->evidenceRef)->first();
            if ($existing !== null && $this->sameDelivery($existing, $fields, $payload)) {
                return $providerEvidence->evidenceRef;
            }
        }
        $ref = 'conflict:'.bin2hex(random_bytes(20));
        $delivery['evidence_ref'] = $ref;
        $delivery['signature_verdict'] = 'quarantined_conflict';
        $delivery['sanitized_payload'] = ExecutionCodec::encode(['externalEvidenceRef' => $providerEvidence->evidenceRef,
            'originalVerdict' => $providerEvidence->signatureVerdict, 'originalPayload' => $providerEvidence->sanitizedPayload]);
        $db->table('billium_execution_provider_evidence')->insert($delivery);

        return $ref;
    }

    public function providerWork(string $workRef, ?TransactionContext $context = null): ?ProviderWorkInput
    {
        $db = $this->tables->database($context);
        $caller = $this->tables->caller($context);
        $base = ['installation_ref' => $caller->installationRef, 'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment];
        $deliveries = $db->table('billium_execution_provider_evidence')->where($base)->whereIn('intent_owner_namespace', $caller->intentOwnerNamespaces);
        if ($context !== null) {
            $deliveries->where('intent_owner_namespace', $context->spec()->financialScope?->intentOwnerNamespace);
        }
        $row = $db->table('billium_execution_provider_work')->where($base)->where('work_ref', $workRef)
            ->whereIn('evidence_ref', (clone $deliveries)->select('evidence_ref'))->first();
        if ($row === null) {
            return null;
        }
        $raw = $deliveries->where('evidence_ref', $row->evidence_ref)->sole();
        $scope = new ExecutionScope($caller->installationRef, $caller->sellerRef, $caller->environment, ExecutionRows::text($raw->intent_owner_namespace));
        $evidence = ExecutionRecords::evidence($scope, $raw);
        $hint = ExecutionRows::nullableText($row->operation_hint) ?? $this->transportHint($evidence, $context);

        return new ProviderWorkInput($evidence, ExecutionRows::text($row->handler_contract_version), ExecutionRows::text($row->state), $hint, ExecutionRows::nullableText($row->result_ref), ExecutionRows::nullableText($row->last_error_code));
    }

    public function enqueueProviderWork(TransactionContext $transactionContext, string $evidenceRef, string $workRef, string $handlerVersion, ?string $operationHint = null): string
    {
        $this->tables->custody($transactionContext);
        $evidence = $this->tables->callerQuery('provider_evidence', $transactionContext)->where('evidence_ref', $evidenceRef)->first() ?? throw new LogicException('Evidence absent');
        $caller = $transactionContext->caller();
        $namespace = ExecutionRows::text($evidence->intent_owner_namespace);
        if (! in_array($namespace, $caller->intentOwnerNamespaces, true)
            || ($transactionContext->spec()->financialScope !== null && $transactionContext->spec()->financialScope->intentOwnerNamespace !== $namespace)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Provider work differs from custody scope');
        }
        $state = $evidence->signature_verdict === 'quarantined_conflict' ? 'quarantined' : 'due';
        $this->tables->database($transactionContext)->table('billium_execution_provider_work')->insertOrIgnore($this->tables->callerScope($transactionContext) + ['evidence_ref' => $evidenceRef,
            'work_ref' => $workRef, 'handler_contract_version' => $handlerVersion, 'state' => $state, 'operation_hint' => $operationHint]);
        $stored = $this->scopedWork($transactionContext)->where('work_ref', $workRef)->first();
        $stored ??= $this->scopedWork($transactionContext)->where('evidence_ref', $evidenceRef)->where('handler_contract_version', $handlerVersion)->first();
        if ($stored === null || $stored->evidence_ref !== $evidenceRef || $stored->handler_contract_version !== $handlerVersion
            || ($operationHint !== null && $stored->operation_hint !== null && $stored->operation_hint !== $operationHint)) {
            ExecutionTables::refuse('Provider work identity conflict');
        }

        return ExecutionRows::text($stored->work_ref);
    }

    public function claimProviderWork(TransactionContext $transactionContext, string $workRef, string $leaseOwner, DateTimeImmutable $until): ?ProviderWorkClaim
    {
        $this->tables->custody($transactionContext);
        $q = $this->scopedWork($transactionContext)->where('work_ref', $workRef);
        $r = (clone $q)->first();
        if ($r === null || $leaseOwner === '' || $until <= new DateTimeImmutable || ! in_array($r->state, ['due', 'leased'], true) || new DateTimeImmutable(ExecutionRows::text($r->due_at)) > new DateTimeImmutable || ($r->state === 'leased' && ExecutionRows::nullableDate($r->lease_expires_at) > new DateTimeImmutable)) {
            return null;
        }
        $fence = ExecutionRows::integer($r->lease_fence) + 1;
        $count = ExecutionRows::integer($r->attempt_count) + 1;
        $updated = $q->where('lease_fence', ExecutionRows::integer($r->lease_fence))->where('state', ExecutionRows::text($r->state))
            ->whereRaw('due_at <= clock_timestamp()')->whereRaw('?::timestamptz > clock_timestamp()', [$until->format(ExecutionRows::TIMESTAMP)])
            ->where(static fn (Builder $eligible): Builder => $eligible->where('state', 'due')->orWhere(static fn (Builder $expired): Builder => $expired->where('state', 'leased')->whereRaw('lease_expires_at <= clock_timestamp()')))
            ->update(['state' => 'leased', 'lease_owner' => $leaseOwner, 'lease_fence' => $fence, 'lease_expires_at' => $until->format(ExecutionRows::TIMESTAMP), 'attempt_count' => $count]);
        if ($updated !== 1) {
            return null;
        }

        return new ProviderWorkClaim($workRef, ExecutionRows::text($r->evidence_ref), ExecutionRows::text($r->handler_contract_version), $count, $fence, $leaseOwner, $until);
    }

    public function finishProviderWork(TransactionContext $transactionContext, ProviderWorkClaim $providerWorkClaim, string $state, ?string $resultRef, ?string $errorCode, ?DateTimeImmutable $dueAt): bool
    {
        $this->tables->custody($transactionContext);
        if (! in_array($state, ['due', 'processed', 'parked', 'quarantined'], true) || ($state === 'due' && ($dueAt === null || $dueAt <= new DateTimeImmutable))) {
            ExecutionTables::refuse('Invalid work completion');
        }

        return $this->scopedWork($transactionContext)->where('evidence_ref', $providerWorkClaim->evidenceRef)->where('work_ref', $providerWorkClaim->workRef)->where('handler_contract_version', $providerWorkClaim->handlerContractVersion)->where('state', 'leased')->where('lease_owner', $providerWorkClaim->leaseOwner)->where('lease_fence', $providerWorkClaim->leaseFence)->whereRaw('lease_expires_at > clock_timestamp()')->update(['state' => $state, 'lease_owner' => null, 'lease_expires_at' => null, 'result_ref' => $resultRef, 'last_error_code' => $errorCode, 'last_error_at' => $errorCode === null ? null : new DateTimeImmutable, 'due_at' => $dueAt ?? new DateTimeImmutable]) === 1;
    }

    /** @param array<string,string|null> $fields */
    private function sameDelivery(stdClass $row, array $fields, string $payload): bool
    {
        foreach ($fields as $column => $value) {
            if ($row->{$column} !== $value) {
                return false;
            }
        }

        return ExecutionCodec::encode(json_decode(ExecutionRows::text($row->sanitized_payload), true, 64, JSON_THROW_ON_ERROR)) === $payload;
    }

    private function transportHint(ProviderEvidence $evidence, ?TransactionContext $context): ?string
    {
        $links = $this->tables->query('provider_evidence', $evidence->scope, $context)->where('intent_owner_namespace', $evidence->scope->intentOwnerNamespace)
            ->where('provider_id', $evidence->providerId)->where('merchant_account_ref', $evidence->merchantAccountRef)
            ->where('handler_contract_version', 'execution.transport.v1')->whereIn('signature_verdict', ['local_transport', 'verified_no_effect'])
            ->where('sanitized_payload->schema', 'billium.execution.transport-observation.v1')
            ->where(function (Builder $q) use ($evidence): void {
                $q->where('evidence_ref', $evidence->evidenceRef)->orWhere('sanitized_payload->retainedProviderEvidenceRef', $evidence->evidenceRef);
            })->get();
        $hint = null;
        foreach ($links as $link) {
            $data = ExecutionCodec::map(ExecutionRows::text($link->sanitized_payload));
            $ref = $data['attemptRef'] ?? null;
            $attempt = is_string($ref) ? $this->tables->attempt($evidence->scope, $ref, $context) : null;
            if ($attempt === null || ($data['providerReference'] ?? null) !== $attempt->providerReference || ($data['operationRef'] ?? null) !== $attempt->operationRef
                || ($hint !== null && $hint !== $attempt->operationRef)) {
                return null;
            }
            $hint = $attempt->operationRef;
        }

        return $hint;
    }

    private function scopedWork(TransactionContext $context): Builder
    {
        $caller = $context->caller();
        $selected = $context->spec()->financialScope;
        $owner = $context->spec()->mode->financial() ? $this->tables->selectedScope($context)->intentOwnerNamespace : $selected?->intentOwnerNamespace;
        if ($owner === null || ! in_array($owner, $caller->intentOwnerNamespaces, true) || $selected?->installationRef !== $caller->installationRef
            || $selected->sellerRef !== $caller->sellerRef || $selected->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Provider work requires the authorized custody owner');
        }
        $evidence = $this->tables->callerQuery('provider_evidence', $context)->where('intent_owner_namespace', $owner);

        return $this->tables->callerQuery('provider_work', $context)->whereIn('evidence_ref', $evidence->select('evidence_ref'));
    }
}
