<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MaterialChange;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ProviderWorkInput;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ReservationState;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
trait ExecutionEvidencePersistence
{
    public function appendEvidence(TransactionContext $transactionContext, ProviderEvidence $providerEvidence): string
    {
        $this->custody($transactionContext);
        $this->assertScope($providerEvidence->scope, $transactionContext);
        $payload = ExecutionCodec::encode($providerEvidence->sanitizedPayload);
        if (strlen($payload) > 65536 || preg_match('/"(?:pan|cvc|cvv|password|secret|accessToken|refreshToken|providerToken)"\s*:/i', $payload) === 1) {
            $this->refuse('Evidence payload is not sanitized/bounded');
        }
        $db = $this->database($transactionContext);
        $scope = $this->scope($providerEvidence->scope);
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

    public function attemptForOperation(ExecutionScope $scope, string $operationRef, ?TransactionContext $context = null): ?AttemptRecord
    {
        $row = $this->query('attempt', $scope, $context)->where('operation_ref', $operationRef)->first();

        return $row === null ? null : $this->attemptRow($row);
    }

    public function reservation(TransactionContext $context, string $reservationRef): ?Reservation
    {
        $this->financial($context);
        $row = $this->callerQuery('reservation', $context)->where('reservation_ref', $reservationRef)
            ->whereIn('operation_ref', $this->callerQuery('operation', $context)->where('intent_owner_namespace', $this->selectedScope($context)->intentOwnerNamespace)->select('operation_ref'))->first();

        return $row === null ? null : $this->reservationRow($context, $row);
    }

    public function reservationForOperation(TransactionContext $context, string $operationRef): ?Reservation
    {
        $this->financial($context);
        $this->requireOperationLock($context, $operationRef);
        $row = $this->callerQuery('reservation', $context)->where('operation_ref', $operationRef)->first();

        return $row === null ? null : $this->reservationRow($context, $row);
    }

    private function reservationRow(TransactionContext $context, stdClass $row): Reservation
    {
        $this->requireOperationLock($context, ExecutionRows::text($row->operation_ref));
        $currency = Currency::of(ExecutionRows::text($row->currency));

        return new Reservation(ExecutionRows::text($row->reservation_ref), ExecutionRows::text($row->root_operation_ref), ExecutionRows::text($row->operation_ref),
            EffectKind::from(ExecutionRows::text($row->kind)), new Money(ExecutionRows::integer($row->requested_minor), $currency),
            ReservationState::from(ExecutionRows::text($row->state)), new Money(ExecutionRows::integer($row->consumed_minor), $currency), ExecutionRows::nullableText($row->release_evidence_ref));
    }

    public function lastObserved(TransactionContext $context, string $operationRef): ?MaterialSnapshot
    {
        $this->financial($context);
        $this->requireOperationLock($context, $operationRef);
        // An accepted provider revision can advance ordering without changing money or creating an operation revision.
        $value = $this->orderingFacts($context)->where('operation_ref', $operationRef)->orderByDesc('id')->value('normalized_snapshot');

        return $value === null ? null : ExecutionCodec::decode(MaterialSnapshot::class, ExecutionRows::text($value));
    }

    private function orderingFacts(TransactionContext $context): Builder
    {
        return $this->callerQuery('provider_fact', $context)->where('evidence_mode', ProviderEvidenceMode::CumulativeSnapshot->value)
            ->whereColumn('normalized_snapshot->operationRef', 'operation_ref')->whereNotNull('normalized_snapshot->bindingDigest')
            ->whereNotNull('normalized_snapshot->providerReference')->where(static fn (Builder $q): Builder => $q
            ->whereNotNull('normalized_snapshot->operationAmount')->orWhereIn('normalized_snapshot->normalizedStatus', ['pending', 'unknown']));
    }

    public function rootOrderingFrontier(TransactionContext $context, string $rootRef, MaterialSnapshot $incoming): ?MaterialSnapshot
    {
        $this->financial($context);
        $this->requireOperationLock($context, $rootRef);
        $root = $this->admittedOperation($context, $rootRef);
        $operations = $this->query('operation', $root->binding->scope, $context)->where('root_operation_ref', $rootRef)->select('operation_ref');
        $query = $this->orderingFacts($context)->whereIn('operation_ref', $operations);
        if ($incoming->providerRevision !== null && preg_match('/^(0|[1-9][0-9]*)$/D', $incoming->providerRevision) === 1) {
            // Comparable decimal revisions sort exactly even when they exceed bigint. A late sibling can have a newer journal id.
            $revision = "(normalized_snapshot->>'providerRevision')";
            $numeric = 'CASE WHEN '.$revision." ~ '^(0|[1-9][0-9]*)$' THEN ".$revision.' END';
            $query->orderByRaw('length('.$numeric.') DESC NULLS LAST')->orderByRaw($numeric.' COLLATE "C" DESC NULLS LAST');
        }
        $value = $query->orderByDesc('id')->value('normalized_snapshot');

        return $value === null ? null : ExecutionCodec::decode(MaterialSnapshot::class, ExecutionRows::text($value));
    }

    public function latestRootObservation(TransactionContext $context, string $rootRef): ?MaterialSnapshot
    {
        $this->financial($context);
        $this->requireOperationLock($context, $rootRef);
        $value = $this->callerQuery('operation_revision', $context)->where('operation_ref', $rootRef)
            ->whereNotNull('observed->authorizedTotal')->orderByDesc('id')->value('observed');

        return $value === null ? null : ExecutionCodec::decode(MaterialSnapshot::class, ExecutionRows::text($value));
    }

    public function providerWork(string $workRef, ?TransactionContext $context = null): ?ProviderWorkInput
    {
        $db = $this->database($context);
        $caller = $context?->caller() ?? $this->callers->current();
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
        $evidence = new ProviderEvidence(ExecutionRows::text($raw->evidence_ref), $scope, ExecutionRows::text($raw->provider_id), ExecutionRows::text($raw->merchant_account_ref),
            ExecutionRows::nullableText($raw->provider_event_id), ExecutionRows::text($raw->payload_digest), ExecutionRows::text($raw->signature_verdict), ExecutionRows::text($raw->handler_contract_version),
            new DateTimeImmutable(ExecutionRows::text($raw->received_at)), ExecutionCodec::map(ExecutionRows::text($raw->sanitized_payload)), ExecutionRows::nullableText($raw->secure_payload_ref));
        $hint = ExecutionRows::nullableText($row->operation_hint) ?? $this->transportHint($evidence, $context);

        return new ProviderWorkInput($evidence, ExecutionRows::text($row->handler_contract_version), ExecutionRows::text($row->state), $hint, ExecutionRows::nullableText($row->result_ref), ExecutionRows::nullableText($row->last_error_code));
    }

    private function transportHint(ProviderEvidence $evidence, ?TransactionContext $context): ?string
    {
        $links = $this->query('provider_evidence', $evidence->scope, $context)->where('intent_owner_namespace', $evidence->scope->intentOwnerNamespace)
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
            $attempt = is_string($ref) ? $this->attempt($evidence->scope, $ref, $context) : null;
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
        $owner = $context->spec()->mode->financial() ? $this->selectedScope($context)->intentOwnerNamespace : $selected?->intentOwnerNamespace;
        if ($owner === null || ! in_array($owner, $caller->intentOwnerNamespaces, true) || $selected?->installationRef !== $caller->installationRef
            || $selected->sellerRef !== $caller->sellerRef || $selected->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Provider work requires the authorized custody owner');
        }
        $evidence = $this->callerQuery('provider_evidence', $context)->where('intent_owner_namespace', $owner);

        return $this->callerQuery('provider_work', $context)->whereIn('evidence_ref', $evidence->select('evidence_ref'));
    }

    public function enqueueProviderWork(TransactionContext $transactionContext, string $evidenceRef, string $workRef, string $handlerVersion, ?string $operationHint = null): string
    {
        $this->custody($transactionContext);
        $evidence = $this->callerQuery('provider_evidence', $transactionContext)->where('evidence_ref', $evidenceRef)->first() ?? throw new LogicException('Evidence absent');
        $caller = $transactionContext->caller();
        $namespace = ExecutionRows::text($evidence->intent_owner_namespace);
        if (! in_array($namespace, $caller->intentOwnerNamespaces, true)
            || ($transactionContext->spec()->financialScope !== null && $transactionContext->spec()->financialScope->intentOwnerNamespace !== $namespace)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Provider work differs from custody scope');
        }
        $state = $evidence->signature_verdict === 'quarantined_conflict' ? 'quarantined' : 'due';
        $this->database($transactionContext)->table('billium_execution_provider_work')->insertOrIgnore($this->callerScope($transactionContext) + ['evidence_ref' => $evidenceRef,
            'work_ref' => $workRef, 'handler_contract_version' => $handlerVersion, 'state' => $state, 'operation_hint' => $operationHint]);
        $stored = $this->scopedWork($transactionContext)->where('work_ref', $workRef)->first();
        $stored ??= $this->scopedWork($transactionContext)->where('evidence_ref', $evidenceRef)->where('handler_contract_version', $handlerVersion)->first();
        if ($stored === null || $stored->evidence_ref !== $evidenceRef || $stored->handler_contract_version !== $handlerVersion
            || ($operationHint !== null && $stored->operation_hint !== null && $stored->operation_hint !== $operationHint)) {
            $this->refuse('Provider work identity conflict');
        }

        return ExecutionRows::text($stored->work_ref);
    }

    public function claimProviderWork(TransactionContext $transactionContext, string $workRef, string $leaseOwner, DateTimeImmutable $until): ?ProviderWorkClaim
    {
        $this->custody($transactionContext);
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
        $this->custody($transactionContext);
        if (! in_array($state, ['due', 'processed', 'parked', 'quarantined'], true) || ($state === 'due' && ($dueAt === null || $dueAt <= new DateTimeImmutable))) {
            $this->refuse('Invalid work completion');
        }

        return $this->scopedWork($transactionContext)->where('evidence_ref', $providerWorkClaim->evidenceRef)->where('work_ref', $providerWorkClaim->workRef)->where('handler_contract_version', $providerWorkClaim->handlerContractVersion)->where('state', 'leased')->where('lease_owner', $providerWorkClaim->leaseOwner)->where('lease_fence', $providerWorkClaim->leaseFence)->whereRaw('lease_expires_at > clock_timestamp()')->update(['state' => $state, 'lease_owner' => null, 'lease_expires_at' => null, 'result_ref' => $resultRef, 'last_error_code' => $errorCode, 'last_error_at' => $errorCode === null ? null : new DateTimeImmutable, 'due_at' => $dueAt ?? new DateTimeImmutable]) === 1;
    }

    public function providerOperationId(ExecutionScope $scope, string $attemptRef): ?string
    {
        $attempt = $this->query('attempt', $scope)->where('attempt_ref', $attemptRef);

        return ExecutionRows::nullableText($this->query('provider_identity', $scope)->whereIn('attempt_ref', $attempt->select('attempt_ref'))->value('provider_operation_id'));
    }

    public function operationByProviderId(ExecutionScope $scope, string $providerId, string $merchantAccountRef, string $providerNamespace, string $providerOperationId): ?OperationRecord
    {
        $row = $this->query('provider_identity', $scope)->whereRaw('public.billium_execution_provider_identity_key(environment,provider_id,merchant_account_ref,provider_namespace,provider_operation_id) = public.billium_execution_provider_identity_key(?,?,?,?,?)',
            [$scope->environment, $providerId, $merchantAccountRef, $providerNamespace, $providerOperationId])
            ->where('provider_id', $providerId)->where('merchant_account_ref', $merchantAccountRef)
            ->where('provider_namespace', $providerNamespace)->where('provider_operation_id', $providerOperationId)->first();
        $attempt = $row === null ? null : $this->attempt($scope, ExecutionRows::text($row->attempt_ref));

        return $attempt === null ? null : $this->operation($scope, $attempt->operationRef);
    }

    public function bindProviderOperationId(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact): bool
    {
        $identity = $this->qualifiedFactIdentity($context, $attempt, $fact);
        if ($identity === null) {
            return true;
        }
        $scope = $this->selectedScope($context);
        $byAttempt = $this->callerQuery('provider_identity', $context)->where('attempt_ref', $attempt->attemptRef);
        if ((clone $byAttempt)->exists()) {
            unset($identity['evidence_ref']);

            return $byAttempt->where($identity)->exists();
        }

        return ! $this->database($context)->table('billium_execution_provider_identity')->whereRaw('public.billium_execution_provider_identity_key(environment,provider_id,merchant_account_ref,provider_namespace,provider_operation_id) = public.billium_execution_provider_identity_key(?,?,?,?,?)',
            [$scope->environment, $identity['provider_id'], $identity['merchant_account_ref'], $identity['provider_namespace'], $identity['provider_operation_id']])->exists();
    }

    /** @return array<string,string>|null */
    private function qualifiedFactIdentity(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact): ?array
    {
        $this->financial($context);
        $operation = $this->admittedOperation($context, $fact->operationRef);
        $this->requireOperationLock($context, $operation->operationRef);
        $scope = $operation->binding->scope;
        $stored = $this->attemptForOperation($scope, $fact->operationRef, $context);
        $snapshot = $fact->snapshot;
        if ($stored === null || $attempt->id !== $stored->id || $attempt->operationRef !== $stored->operationRef || $attempt->attemptRef !== $stored->attemptRef
            || $snapshot->providerReference !== $stored->providerReference || $snapshot->bindingDigest !== ExecutionCodec::bindingDigest($operation->binding)
            || $snapshot->operationRef !== $operation->operationRef || $snapshot->kind !== $operation->kind
            || $snapshot->merchantAccountRef !== $operation->binding->merchantAccountRef || $snapshot->currency !== $operation->requested->currency->code
            || ($snapshot->operationAmount === null && (! in_array($snapshot->normalizedStatus, ['pending', 'unknown'], true) || $fact->mode !== ProviderEvidenceMode::CumulativeSnapshot))
            || ($snapshot->operationAmount !== null && ExecutionCodec::encode($snapshot->operationAmount->currency) !== ExecutionCodec::encode($operation->requested->currency))
            || $fact->evidenceRefs === [] || $fact->evidenceRefs !== $snapshot->evidenceRefs) {
            $this->refuse('Provider fact differs from original attempt, binding or actual amount');
        }
        $merchant = $this->merchantRevision($scope, $operation->binding->merchantRevisionRef, $context);
        $refs = array_unique($fact->evidenceRefs);
        $evidence = $this->query('provider_evidence', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->where('merchant_account_ref', $snapshot->merchantAccountRef)->where('provider_id', $merchant->providerId)
            ->whereNotIn('signature_verdict', ['quarantined_conflict', 'verified_no_effect', 'local_transport'])->whereIn('evidence_ref', $refs);
        if ($evidence->count() !== count($refs)) {
            $this->refuse('Authenticated evidence absent from original financial scope');
        }
        $capability = $this->capability($scope, $merchant->capabilitySnapshotRef, $context);
        if ($fact->providerIdentityScope !== ExecutionCodec::providerIdentityScope($merchant, $capability)) {
            $this->refuse('Provider fact namespace differs from frozen provider, account and environment');
        }
        if ($snapshot->providerOperationId === null) {
            return null;
        }
        $namespace = $capability->details->lookupNamespace;

        return ['attempt_ref' => $attempt->attemptRef, 'provider_id' => $merchant->providerId, 'merchant_account_ref' => $merchant->merchantAccountRef,
            'provider_namespace' => $namespace, 'provider_operation_id' => $snapshot->providerOperationId, 'evidence_ref' => $refs[0]];
    }

    public function factByIdentity(TransactionContext $context, string $providerIdentityScope, string $factIdentity): ?ProviderFact
    {
        $this->financial($context);
        $owned = $this->callerQuery('operation', $context)->where('intent_owner_namespace', $this->selectedScope($context)->intentOwnerNamespace)->select('operation_ref');
        $row = $this->callerQuery('provider_fact', $context)->where('provider_identity_scope', $providerIdentityScope)->where('fact_identity', $factIdentity)
            ->whereNotNull('normalized_snapshot->bindingDigest')->whereNotNull('normalized_snapshot->providerReference')->whereIn('operation_ref', $owned)->first();

        return $row === null ? null : $this->factRow($row);
    }

    public function factForEvidence(TransactionContext $context, string $evidenceRef, string $operationRef): ?ProviderFact
    {
        $this->financial($context);
        $this->requireOperationLock($context, $operationRef);
        $query = $this->callerQuery('provider_fact', $context)->where('operation_ref', $operationRef)->whereJsonContains('evidence_refs', $evidenceRef)
            ->whereNotNull('normalized_snapshot->bindingDigest')->whereNotNull('normalized_snapshot->operationAmount');
        $rows = (clone $query)->where('evidence_mode', ProviderEvidenceMode::CumulativeSnapshot->value)->limit(2)->get();
        if ($rows->isEmpty()) {
            $rows = $query->limit(2)->get();
        }

        return $rows->count() === 1 ? $this->factRow($rows->sole()) : null;
    }

    private function factRow(stdClass $r): ProviderFact
    {
        return new ProviderFact(ExecutionRows::text($r->fact_ref), ExecutionRows::text($r->operation_ref), ExecutionRows::text($r->provider_identity_scope), ExecutionRows::text($r->fact_identity),
            ExecutionRows::text($r->material_digest), ProviderEvidenceMode::from(ExecutionRows::text($r->evidence_mode)), ExecutionCodec::decode(MaterialSnapshot::class, ExecutionRows::text($r->normalized_snapshot)),
            ExecutionRows::list(ExecutionRows::text($r->evidence_refs)));
    }

    public function appendFact(TransactionContext $transactionContext, ProviderFact $providerFact): bool
    {
        $this->financial($transactionContext);
        $operation = $this->admittedOperation($transactionContext, $providerFact->operationRef);
        $this->requireOperationLock($transactionContext, $operation->operationRef);
        $attempt = $this->attemptForOperation($operation->binding->scope, $operation->operationRef, $transactionContext) ?? throw new LogicException('Original attempt absent');
        $db = $this->database($transactionContext);
        $identity = $this->qualifiedFactIdentity($transactionContext, $attempt, $providerFact);
        $row = $this->callerScope($transactionContext) + ['fact_ref' => $providerFact->factRef, 'operation_ref' => $providerFact->operationRef,
            'provider_identity_scope' => $providerFact->providerIdentityScope, 'fact_identity' => $providerFact->factIdentity, 'evidence_mode' => $providerFact->mode->value,
            'material_digest' => $providerFact->materialDigest, 'normalized_snapshot' => $providerFact->snapshot, 'evidence_refs' => $providerFact->evidenceRefs];
        $legacy = $this->hasUnqualifiedHistory($transactionContext, $operation, $providerFact);
        $result = $legacy ? null : $db->selectOne('SELECT public.billium_execution_accept_provider_fact(?::jsonb, ?::jsonb) AS inserted',
            [ExecutionCodec::encode($row), $identity === null ? null : ExecutionCodec::encode($identity)]);
        if ($result instanceof stdClass && $result->inserted === true) {
            return true;
        }
        $winner = $this->factByIdentity($transactionContext, $providerFact->providerIdentityScope, $providerFact->factIdentity);
        if ($legacy || $winner === null || $winner->operationRef !== $operation->operationRef || $winner->materialDigest !== $providerFact->materialDigest
            || $winner->mode !== $providerFact->mode || $winner->snapshot->providerOperationId !== $providerFact->snapshot->providerOperationId) {
            $db->table('billium_execution_discrepancy')->insertOrIgnore($this->callerScope($transactionContext) + ['discrepancy_ref' => 'fact-conflict:'.hash('sha256', ExecutionCodec::encode($providerFact)),
                'root_operation_ref' => $operation->rootOperationRef, 'operation_ref' => $operation->operationRef, 'kind' => 'provider_fact_identity_conflict',
                'evidence_refs' => ExecutionCodec::encode($providerFact->evidenceRefs), 'observed' => ExecutionCodec::encode($providerFact->snapshot),
                'last_consistent_applied' => ExecutionCodec::encode($this->capacity($transactionContext, $operation->rootOperationRef)),
                'exposure' => ExecutionCodec::encode(['disposition' => 'quarantined_identity', 'factRef' => $providerFact->factRef])]);
        }

        return false;
    }

    private function hasUnqualifiedHistory(TransactionContext $context, OperationRecord $operation, ProviderFact $fact): bool
    {
        $merchant = $this->merchantRevision($operation->binding->scope, $operation->binding->merchantRevisionRef, $context);
        $namespace = $this->capability($operation->binding->scope, $merchant->capabilitySnapshotRef, $context)->details->lookupNamespace;
        $history = $this->database($context)->selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM billium_execution_provider_fact f
                JOIN billium_execution_operation o USING (installation_ref,seller_ref,environment,operation_ref)
                JOIN billium_execution_merchant_revision m ON m.installation_ref=o.installation_ref AND m.seller_ref=o.seller_ref
                    AND m.environment=o.environment AND m.revision_ref=o.merchant_revision_ref
                JOIN billium_execution_capability_snapshot c ON c.installation_ref=m.installation_ref AND c.seller_ref=m.seller_ref
                    AND c.environment=m.environment AND c.snapshot_ref=m.capability_snapshot_ref
                WHERE (f.normalized_snapshot->>'bindingDigest' IS NULL OR f.normalized_snapshot->>'providerReference' IS NULL OR
                    (f.normalized_snapshot->>'operationAmount' IS NULL AND f.normalized_snapshot->>'normalizedStatus' NOT IN ('pending','unknown')))
                    AND f.environment=? AND m.provider_id=? AND m.merchant_account_ref=? AND c.body#>>'{details,lookupNamespace}'=?
                    AND o.kind=? AND (f.fact_identity=? OR f.normalized_snapshot->>'stableEffectId'=?
                        OR (f.operation_ref=? AND f.normalized_snapshot->>'providerRevision' IS NOT DISTINCT FROM ?))
            ) AS present
            SQL, [$operation->binding->scope->environment, $merchant->providerId, $merchant->merchantAccountRef, $namespace, $operation->kind->value,
            $fact->factIdentity, $fact->snapshot->stableEffectId, $operation->operationRef, $fact->snapshot->providerRevision]);

        return $history instanceof stdClass && $history->present === true;
    }

    public function applyAttemptFact(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact, AttemptState $state, ?DateTimeImmutable $lookupDueAt): void
    {
        $stored = $this->factByIdentity($context, $fact->providerIdentityScope, $fact->factIdentity);
        $status = $fact->snapshot->normalizedStatus;
        $valid = match ($state) {
            AttemptState::Succeeded => $status === 'succeeded',
            AttemptState::Failed => in_array($status, ['failed', 'declined', 'no_effect'], true) && $fact->snapshot->operationAmount?->minor === 0,
            AttemptState::Pending, AttemptState::Unknown => in_array($status, ['pending', 'unknown'], true),
            default => false,
        };
        if (! $valid || $stored === null || $stored->operationRef !== $attempt->operationRef || $fact->evidenceRefs === [] || ExecutionCodec::encode($stored) !== ExecutionCodec::encode($fact)) {
            $this->refuse('Attempt transition requires original accepted fact');
        }
        $this->advanceAttempt($context, $attempt, $state, $fact->evidenceRefs[0], $lookupDueAt);
    }

    private function advanceAttempt(TransactionContext $context, AttemptRecord $attempt, AttemptState $state, string $evidenceRef, ?DateTimeImmutable $lookupDueAt): void
    {
        $this->requireOperationLock($context, $attempt->operationRef);
        $q = $this->callerQuery('attempt', $context)->where('id', $attempt->id)->where('attempt_ref', $attempt->attemptRef)->where('operation_ref', $attempt->operationRef);
        $r = (clone $q)->first() ?? throw new LogicException('Original attempt absent');
        if (! ($this->held[$context]['billium_execution_attempt:'.$attempt->id] ?? false) || $r->lease_fence !== $attempt->leaseFence
            || $r->provider_reference !== $attempt->providerReference || $r->request_digest !== $attempt->immutableRequestDigest) {
            $this->refuse('Original locked attempt and current fence required');
        }
        $terminal = in_array($state, [AttemptState::Succeeded, AttemptState::Failed], true);
        if ($terminal !== ($lookupDueAt === null) || ($lookupDueAt !== null && $lookupDueAt <= new DateTimeImmutable)) {
            $this->refuse('Attempt lookup deadline differs from factual state');
        }
        if (in_array($r->state, ['succeeded', 'failed', 'cancelled'], true)) {
            if ($state->value === $r->state) {
                return;
            }
            if (in_array($r->state, ['cancelled', 'failed'], true) && $state === AttemptState::Succeeded
                && $this->capacity($context, $this->admittedOperation($context, $attempt->operationRef)->rootOperationRef)->financialHold) {
                return;
            }
            $this->refuse('Terminal attempt cannot regress');
        }
        if ($r->started_at === null || ! in_array($r->state, ['dispatching', 'pending', 'unknown'], true)) {
            $this->refuse('Factual completion requires original post-dispatch attempt');
        }
        $work = $this->callerQuery('dispatch_work', $context)->where('attempt_ref', $attempt->attemptRef)->where('operation_ref', $attempt->operationRef)->where('lease_fence', $attempt->leaseFence);
        $revoke = ['lease_owner' => null, 'lease_expires_at' => null, 'lease_fence' => $attempt->leaseFence + 1];
        $updated = $work->update($revoke + ['state' => $terminal ? 'processed' : 'due', 'due_at' => $lookupDueAt?->format(ExecutionRows::TIMESTAMP)]);
        $imported = $updated === 0 && $this->callerQuery('original_identity_import', $context)->where('attempt_ref', $attempt->attemptRef)
            ->where('operation_ref', $attempt->operationRef)->where('intent_owner_namespace', $this->selectedScope($context)->intentOwnerNamespace)->exists()
            && ! $this->callerQuery('dispatch_work', $context)->where('attempt_ref', $attempt->attemptRef)->exists();
        if (($updated !== 1 && ! $imported)
            || $q->where('lease_fence', $attempt->leaseFence)->update($revoke + ['state' => $state->value, 'finish_evidence_ref' => $evidenceRef, 'dispatch_ticket_ref' => null]) !== 1) {
            $this->refuse('Dispatch fence changed during factual settlement');
        }
    }

    public function applyCertifiedAbsence(TransactionContext $context, AttemptRecord $attempt, ProviderWorkClaim $claim, VerifiedAbsence $proof, ?CapacitySnapshot $releasedCapacity): void
    {
        $this->financial($context);
        $this->requireIssuedProof($proof);
        $operation = $this->admittedOperation($context, $attempt->operationRef);
        $scope = $operation->binding->scope;
        $this->requireOperationLock($context, $operation->operationRef);
        $certificate = $proof->certificate;
        $stored = $this->attemptForOperation($scope, $operation->operationRef, $context);
        $now = new DateTimeImmutable;
        if ($stored === null || ExecutionCodec::encode($attempt) !== ExecutionCodec::encode($stored)
            || ! in_array($stored->state, [AttemptState::Dispatching, AttemptState::Unknown, AttemptState::Pending], true)
            || $proof->attemptRef !== $stored->attemptRef || $proof->operationRef !== $operation->operationRef
            || $stored->providerReference !== $certificate->providerReference || $stored->startedAt === null || $stored->requestDeadlineAt === null
            || $certificate->coverageFrom > $stored->startedAt || $certificate->coverageThrough < $stored->requestDeadlineAt
            || $certificate->coverageThrough > $now || $certificate->expiresAt <= $now || ExecutionCodec::encode($certificate->binding) !== ExecutionCodec::encode($operation->binding)) {
            $this->refuse('Certified absence does not cover this current attempt');
        }
        $merchant = $this->merchantRevision($scope, $operation->binding->merchantRevisionRef, $context);
        $cap = $this->capability($scope, $merchant->capabilitySnapshotRef, $context);
        $work = $this->providerWork($claim->workRef, $context);
        if ($work === null || $work->state !== 'leased' || $work->handlerVersion !== $claim->handlerContractVersion || $work->operationHint !== $operation->operationRef
            || $work->evidence->evidenceRef !== $claim->evidenceRef || $work->evidence->handlerContractVersion !== 'execution.transport.v1'
            || ExecutionCodec::encode($work->evidence->scope) !== ExecutionCodec::encode($scope)
            || $work->evidence->providerId !== $merchant->providerId || $work->evidence->merchantAccountRef !== $merchant->merchantAccountRef
            || $certificate->providerNamespace !== $cap->details->lookupNamespace || $certificate->qualificationEvidenceRef !== $cap->qualificationEvidenceRef) {
            $this->refuse('Verified absence is not the original qualified transport claim');
        }
        $transport = $work->evidence->sanitizedPayload;
        $expected = ['schema' => 'billium.execution.transport-observation.v1', 'attemptRef' => $attempt->attemptRef, 'operationRef' => $operation->operationRef,
            'providerReference' => $attempt->providerReference, 'lookupState' => 'certified_absent', 'providerEvidenceRef' => null,
            'absenceCertificate' => ExecutionCodec::value($certificate)];
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $transport) || ExecutionCodec::encode($transport[$key]) !== ExecutionCodec::encode($value)) {
                $this->refuse('Absence receipt differs from original certificate');
            }
        }
        $fence = $transport['leaseFence'] ?? null;
        if (! is_string($fence) || ! ctype_digit($fence) || (int) $fence > $attempt->leaseFence || ($transport['source'] ?? null) !== 'verified_absence'
            || ($transport['verifiedAbsence'] ?? null) !== true || $work->evidence->signatureVerdict !== 'verified_no_effect') {
            $this->refuse('Absence receipt lacks original verified lookup');
        }
        $lookup = $this->query('provider_evidence', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->where('provider_id', $merchant->providerId)->where('merchant_account_ref', $merchant->merchantAccountRef)->where('handler_contract_version', 'execution.transport.v1')
            ->where('signature_verdict', 'local_transport')->whereJsonContains('sanitized_payload', $expected + ['source' => 'lookup', 'leaseFence' => $fence, 'verifiedAbsence' => false]);
        if (! $lookup->exists() || $this->hasGreaterActual($context, $operation->operationRef, 0)) {
            $this->refuse('Absence lacks complete original lookup or contradicts recorded operation effect');
        }
        if (! $this->finishProviderWork($context, $claim, 'processed', $certificate->certificateRef, null, null)) {
            $this->refuse('Verified absence processing lease changed');
        }
        $this->database($context)->table('billium_execution_certified_absence')->insert($this->callerScope($context) + ['attempt_ref' => $attempt->attemptRef,
            'evidence_ref' => $claim->evidenceRef, 'certificate_ref' => $certificate->certificateRef, 'certificate_digest' => ExecutionCodec::digest($certificate)]);
        $reservation = $this->reservationForOperation($context, $operation->operationRef);
        $capacity = $this->capacity($context, $operation->rootOperationRef);
        if ($reservation !== null) {
            if ($reservation->state !== ReservationState::Held || $releasedCapacity === null) {
                $this->refuse('Certified release requires the original held reservation');
            }
            $held = match ($reservation->kind) {
                EffectKind::Capture => 'heldCapture', EffectKind::VoidAuthorization => 'heldVoid', EffectKind::Refund => 'heldRefund',
                default => throw new LogicException('Invalid reservation kind'),
            };
            $expectedCapacity = get_object_vars($capacity);
            $expectedCapacity['version'] = $capacity->version + 1;
            $expectedCapacity[$held] = new Money($capacity->{$held}->minor - $reservation->requested->minor, $reservation->requested->currency);
            if (ExecutionCodec::encode($expectedCapacity) !== ExecutionCodec::encode($releasedCapacity)) {
                $this->refuse('Certified release differs from original reservation amount');
            }
            $this->settleReservation($context, $reservation->reservationRef, ReservationState::Released, new Money(0, $reservation->requested->currency), $claim->evidenceRef, $releasedCapacity);
            $capacity = $releasedCapacity;
        } elseif ($releasedCapacity !== null) {
            $this->refuse('Certified release has no original reservation');
        }
        $this->advanceAttempt($context, $attempt, AttemptState::Failed, $claim->evidenceRef, null);
        $decision = ['schema' => 'billium.execution.certified-no-effect.v1', 'operationRef' => $operation->operationRef, 'attemptRef' => $attempt->attemptRef,
            'certificateRef' => $certificate->certificateRef, 'bindingDigest' => ExecutionCodec::bindingDigest($operation->binding),
            'operationAmount' => new Money(0, $operation->requested->currency)->toWire(), 'aggregateDisposition' => 'no_provider_material'];
        $digest = ExecutionCodec::digest($decision);
        $revision = $operation->revision + 1;
        $updated = $this->callerQuery('operation', $context)->where('id', $operation->id)->where('revision', $operation->revision)
            ->update(['state' => 'failed', 'revision' => $revision, 'economic_digest' => $digest]);
        if ($updated !== 1) {
            $this->refuse('Original operation changed during certified absence');
        }
        $this->database($context)->table('billium_execution_operation_revision')->insert($this->callerScope($context) + ['operation_ref' => $operation->operationRef,
            'revision' => $revision, 'material_digest' => $digest, 'requested_minor' => $operation->requested->minor, 'currency' => $operation->requested->currency->code,
            'observed' => ExecutionCodec::encode($decision), 'last_consistent_applied' => ExecutionCodec::encode($capacity), 'financial_hold' => ExecutionCodec::encode($capacity->financialHold),
            'exposure' => '{}', 'evidence_refs' => ExecutionCodec::encode([$claim->evidenceRef]), 'receipt_ref' => $claim->workRef, 'occurred_at' => $now]);
    }

    private function hasGreaterActual(TransactionContext $context, string $operationRef, int $minor): bool
    {
        return $this->callerQuery('provider_fact', $context)->where('operation_ref', $operationRef)
            ->where(function (Builder $q) use ($minor): void {
                $q->whereRaw("(normalized_snapshot#>>'{operationAmount,minor}')::bigint > ?", [$minor])
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('normalized_snapshot->operationAmount')->where('normalized_snapshot->normalizedStatus', 'succeeded');
                    });
            })->exists();
    }

    private function requireIssuedProof(VerifiedAbsence $proof): void
    {
        if ($this->absenceProofs === null) {
            $this->refuse('Configured absence verifier unavailable');
        }
        $this->absenceProofs->assertIssued($proof);
    }

    public function assertCertifiedDecision(TransactionContext $context, OperationRecord $operation, VerifiedAbsence $proof): void
    {
        $this->financial($context);
        $this->requireIssuedProof($proof);
        $this->requireOperationLock($context, $operation->operationRef);
        $current = $this->admittedOperation($context, $operation->operationRef);
        $cert = $proof->certificate;
        $record = $this->callerQuery('certified_absence', $context)->where('certificate_ref', $cert->certificateRef)->where('attempt_ref', $proof->attemptRef)
            ->where('certificate_digest', ExecutionCodec::digest($cert))->first();
        $revision = $this->callerQuery('operation_revision', $context)->where('operation_ref', $operation->operationRef)->where('revision', $operation->revision)
            ->where('observed->schema', 'billium.execution.certified-no-effect.v1')->where('observed->certificateRef', $cert->certificateRef)
            ->where('material_digest', $operation->economicDigest)->where('observed->attemptRef', $proof->attemptRef);
        if ($record === null || $operation->state !== 'failed' || $proof->operationRef !== $operation->operationRef
            || ExecutionCodec::encode($current) !== ExecutionCodec::encode($operation) || ! $revision->whereJsonContains('evidence_refs', ExecutionRows::text($record->evidence_ref))->exists()) {
            $this->refuse('Publication requires the original persisted certified absence revision');
        }
    }

    public function assertMaterialRevision(TransactionContext $context, MaterialChange $change): void
    {
        $this->financial($context);
        $op = $change->after;
        $this->requireOperationLock($context, $op->operationRef);
        $current = $this->admittedOperation($context, $op->operationRef);
        $row = $this->callerQuery('operation_revision', $context)->where('operation_ref', $op->operationRef)->where('revision', $op->revision)->first();
        if ($row === null || $change->observed->operationAmount === null || $change->before->operationRef !== $op->operationRef || $change->before->revision + 1 !== $op->revision
            || ExecutionCodec::encode($current) !== ExecutionCodec::encode($op) || $row->material_digest !== $op->economicDigest
            || ExecutionCodec::encode(ExecutionCodec::map(ExecutionRows::text($row->observed))) !== ExecutionCodec::encode($change->observed)
            || ExecutionCodec::encode(ExecutionCodec::map(ExecutionRows::text($row->last_consistent_applied))) !== ExecutionCodec::encode($change->capacity)
            || ExecutionCodec::encode(ExecutionCodec::map(ExecutionRows::text($row->exposure))) !== $this->exposure($change)
            || ExecutionCodec::encode($this->capacity($context, $op->rootOperationRef)) !== ExecutionCodec::encode($change->capacity)) {
            $this->refuse('Publication requires the original persisted material revision');
        }
    }

    public function appendRevision(TransactionContext $transactionContext, MaterialChange $materialChange, string $receiptRef): void
    {
        $a = $materialChange->after;
        $b = $materialChange->before;
        $this->financial($transactionContext);
        $this->requireOperationLock($transactionContext, $b->operationRef);
        $observed = $materialChange->observed;
        $subject = $this->admittedOperation($transactionContext, $observed->operationRef);
        $accepted = isset($observed->evidenceRefs[0]) ? $this->factForEvidence($transactionContext, $observed->evidenceRefs[0], $subject->operationRef) : null;
        if (($materialChange->discrepancyRef !== null && ! $materialChange->capacity->financialHold)
            || $accepted === null || ExecutionCodec::encode($accepted->snapshot) !== ExecutionCodec::encode($observed)
            || $subject->rootOperationRef !== $b->rootOperationRef || ($b->operationRef !== $subject->operationRef && $b->operationRef !== $subject->rootOperationRef)
            || $materialChange->capacity->rootOperationRef !== $b->rootOperationRef) {
            $this->refuse('Material revision requires accepted original operation evidence');
        }
        if (ExecutionCodec::encode($this->admittedOperation($transactionContext, $b->operationRef)) !== ExecutionCodec::encode($b)) {
            $this->refuse('Material revision differs from historical operation');
        }
        if ($a->revision !== $b->revision + 1 || ExecutionCodec::encode($a->binding) !== ExecutionCodec::encode($b->binding) || ExecutionCodec::encode($a->requested) !== ExecutionCodec::encode($b->requested) || $a->kind !== $b->kind || $a->operationRef !== $b->operationRef || $a->rootOperationRef !== $b->rootOperationRef) {
            $this->refuse('Material revision mutates immutable identity');
        }
        $updated = $this->callerQuery('operation', $transactionContext)->where('operation_ref', $b->operationRef)->where('revision', $b->revision)->where('economic_digest', $b->economicDigest)->update(['state' => $a->state, 'revision' => $a->revision, 'economic_digest' => $a->economicDigest, 'authorization_ref' => $a->authorizationRef]);
        if ($updated !== 1) {
            $this->refuse('Material revision conflict');
        }
        $currentCapacity = $this->capacity($transactionContext, $b->rootOperationRef);
        if ($materialChange->capacity->version !== $currentCapacity->version) {
            $this->writeCapacity($transactionContext, $materialChange->capacity, $currentCapacity->version);
        } elseif (ExecutionCodec::encode($materialChange->capacity) !== ExecutionCodec::encode($currentCapacity)) {
            $this->refuse('Material revision differs from unchanged capacity version');
        }
        $this->database($transactionContext)->table('billium_execution_operation_revision')->insert($this->callerScope($transactionContext) + ['operation_ref' => $a->operationRef, 'revision' => $a->revision, 'material_digest' => $a->economicDigest, 'requested_minor' => $a->requested->minor, 'currency' => $a->requested->currency->code, 'observed' => ExecutionCodec::encode($materialChange->observed), 'last_consistent_applied' => ExecutionCodec::encode($materialChange->capacity), 'financial_hold' => ExecutionCodec::encode($materialChange->capacity->financialHold), 'exposure' => $this->exposure($materialChange), 'evidence_refs' => ExecutionCodec::encode($materialChange->observed->evidenceRefs), 'receipt_ref' => $receiptRef, 'occurred_at' => $materialChange->observed->observedAt]);
    }

    public function appendDiscrepancy(TransactionContext $transactionContext, string $ref, MaterialChange $materialChange): void
    {
        $this->financial($transactionContext);
        $prior = $this->admittedOperation($transactionContext, $materialChange->before->operationRef);
        $this->requireLock($transactionContext, $prior->rootOperationRef);
        if ($prior->rootOperationRef !== $materialChange->before->rootOperationRef) {
            $this->refuse('Discrepancy differs from historical root');
        }
        $accepted = $this->factForEvidence($transactionContext, $materialChange->observed->evidenceRefs[0] ?? '', $prior->operationRef);
        if (! $materialChange->capacity->financialHold || $materialChange->discrepancyRef !== $ref || $accepted === null || ExecutionCodec::encode($accepted->snapshot) !== ExecutionCodec::encode($materialChange->observed)) {
            $this->refuse('Accepted material discrepancy requires the original qualified fact');
        }
        $this->database($transactionContext)->table('billium_execution_discrepancy')->insertOrIgnore($this->callerScope($transactionContext) + ['discrepancy_ref' => $ref, 'root_operation_ref' => $materialChange->before->rootOperationRef, 'operation_ref' => $materialChange->before->operationRef, 'kind' => 'provider_material_conflict', 'evidence_refs' => ExecutionCodec::encode($materialChange->observed->evidenceRefs), 'observed' => ExecutionCodec::encode($materialChange->observed), 'last_consistent_applied' => ExecutionCodec::encode($materialChange->capacity), 'exposure' => $this->exposure($materialChange)]);
    }

    public function recordQuarantineHold(TransactionContext $context, ProviderWorkClaim $claim, MaterialSnapshot $conflict, string $discrepancyRef, string $reason): ?OperationRecord
    {
        $this->financial($context);
        $delivery = $this->verifiedReduction?->verifiedIn($context, $claim, $conflict) ?? throw new LogicException('Configured provider verification is required');
        $subject = $this->admittedOperation($context, $conflict->operationRef);
        $this->requireOperationLock($context, $subject->operationRef);
        $root = $this->admittedOperation($context, $subject->rootOperationRef);
        $work = $this->providerWork($claim->workRef, $context);
        $attempt = $this->attemptForOperation($subject->binding->scope, $subject->operationRef, $context);
        if ($work === null || $work->state !== 'leased' || $work->operationHint !== $subject->operationRef || $work->evidence->evidenceRef !== $claim->evidenceRef
            || ExecutionCodec::encode($work->evidence) !== ExecutionCodec::encode($delivery->evidence) || $conflict->evidenceRefs !== [$claim->evidenceRef]
            || $conflict->bindingDigest !== ExecutionCodec::bindingDigest($subject->binding) || $conflict->merchantAccountRef !== $subject->binding->merchantAccountRef
            || $attempt === null || $conflict->providerReference !== $attempt->providerReference || $conflict->kind !== $subject->kind) {
            $this->refuse('Financial hold requires authenticated work for the original locked operation');
        }
        $current = $this->capacity($context, $root->operationRef);
        $held = new CapacitySnapshot($root->operationRef, $current->version + 1, $current->authorizedLimit, $current->confirmedCapture, $current->confirmedRefund,
            $current->releasedAuthorization, $current->heldCapture, $current->heldVoid, $current->heldRefund, true);
        $exposure = ExecutionCodec::encode(['discrepancyRef' => $discrepancyRef, 'financialHold' => true, 'reason' => $reason]);
        $evidence = ExecutionCodec::encode($conflict->evidenceRefs);
        $db = $this->database($context);
        $scope = $this->callerScope($context);
        $db->table('billium_execution_discrepancy')->insertOrIgnore($scope + ['discrepancy_ref' => $discrepancyRef, 'root_operation_ref' => $root->operationRef,
            'operation_ref' => $subject->operationRef, 'kind' => 'provider_material_conflict', 'evidence_refs' => $evidence, 'observed' => ExecutionCodec::encode($conflict),
            'last_consistent_applied' => ExecutionCodec::encode($current), 'exposure' => $exposure]);
        // Existing discrepancy rows may predate local hold publications. Deduplicate against the actual financial revision.
        if ($this->callerQuery('operation_revision', $context)->where('operation_ref', $root->operationRef)
            ->where('observed->discrepancyRef', $discrepancyRef)->where('observed->schema', 'billium.execution.quarantine-hold.v1')->exists()) {
            return null;
        }
        $decision = ['schema' => 'billium.execution.quarantine-hold.v1', 'discrepancyRef' => $discrepancyRef, 'operationRef' => $subject->operationRef,
            'reason' => $reason, 'priorEconomicDigest' => $root->economicDigest];
        $digest = ExecutionCodec::digest($decision);
        $revision = $root->revision + 1;
        if ($this->callerQuery('operation', $context)->where('id', $root->id)->where('revision', $root->revision)->where('economic_digest', $root->economicDigest)
            ->update(['revision' => $revision, 'economic_digest' => $digest]) !== 1) {
            $this->refuse('Root changed before recording the financial hold');
        }
        $this->writeCapacity($context, $held, $current->version);
        $db->table('billium_execution_operation_revision')->insert($scope + ['operation_ref' => $root->operationRef, 'revision' => $revision,
            'material_digest' => $digest, 'requested_minor' => $root->requested->minor, 'currency' => $root->requested->currency->code,
            'observed' => ExecutionCodec::encode($decision), 'last_consistent_applied' => ExecutionCodec::encode($held), 'financial_hold' => 'true',
            'exposure' => $exposure, 'evidence_refs' => $evidence, 'receipt_ref' => $claim->workRef, 'occurred_at' => new DateTimeImmutable]);

        return $this->admittedOperation($context, $root->operationRef);
    }

    private function exposure(MaterialChange $change): string
    {
        return ExecutionCodec::encode(['discrepancyRef' => $change->discrepancyRef, 'financialHold' => $change->capacity->financialHold || $change->discrepancyRef !== null,
            'overCaptured' => $change->overCaptured, 'overRefunded' => $change->overRefunded, 'reason' => $change->reason]);
    }
}
