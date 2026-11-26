<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Dispatch;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Admission\ExecutionReadinessGuard;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Recovery\AttemptJournal;
use Billium\Execution\Internal\Recovery\OriginalRequestReplay;
use Billium\Execution\Internal\Recovery\QualifiedAbsence;
use DateTimeImmutable;
use Exception;
use Packman\Attributes\Internal;

#[Internal]
final readonly class OneAttemptWorker implements AttemptWorker
{
    public function __construct(private ExecutionStore $store, private CallerContext $callers, private MerchantRegistry $merchants,
        private ContinuityMerchantCredentials $credentials, private QualifiedAdapter $adapters, private TransportBoundary $boundary,
        private AttemptLeases $leases, private CommitDispatch $dispatchStart, private DispatchAuthority $authority,
        private AttemptJournal $journal, private QualifiedAbsence $absence, private OriginalRequestReplay $originalReplay, private ExecutionReadinessGuard $readiness) {}

    public function replayOriginalWithinWindow(ExecutionScope $scope, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil, string $approvalRef): StoredOutcome
    {
        return $this->originalReplay->replay($scope, $attemptRef, $worker, $leaseUntil, $approvalRef, $this->retain(...));
    }

    public function dispatch(ExecutionScope $scope, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        [$operation, $attempt] = $this->records($scope, $attemptRef);
        if ($attempt->state !== AttemptState::Admitted) {
            return $this->recover($scope, $attemptRef, $worker, $leaseUntil);
        }
        $binding = $operation->binding;
        $this->readiness->assertReady($binding, $operation->kind, $operation->requested);
        $merchant = $this->merchants->historical($scope, $operation->rootOperationRef);
        [$driver, $capability] = $this->adapters->select($merchant, $operation->kind, $operation->requested->currency->code);
        $purpose = match ($operation->kind) {
            EffectKind::Charge, EffectKind::Authorize => 'new',
            default => $operation->kind->value,
        };
        $credentials = $this->credentials->resolve($merchant, $purpose);
        if ($credentials->purpose !== $purpose || $credentials->merchantAccountRef !== $binding->merchantAccountRef || $credentials->expiresAt <= new DateTimeImmutable) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Resolved credential cannot perform the historical payment action');
        }
        $claimed = $this->leases->claim($operation, $attempt, $worker, $leaseUntil);
        [$ticket, $decision] = $this->dispatchStart->start($operation, $claimed, $credentials);
        $request = new DriverRequest($decision, $binding, $capability, $operation->requested, $claimed->providerReference, $credentials);
        try {
            $result = $this->authority->send($ticket, $request, static fn (): ProviderLookupResult => match ($operation->kind) {
                EffectKind::Charge => $driver->execute($request),
                EffectKind::Authorize => $driver->authorize($request),
                EffectKind::Capture => $driver->capture($request),
                EffectKind::VoidAuthorization => $driver->voidAuthorization($request),
                EffectKind::Refund => $driver->refund($request),
            });
        } catch (Exception) {
            // A throw after dispatch-start can occur on either side of remote acceptance.
            $result = new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'dispatch_result_unavailable', null);
        }

        return $this->retain($operation, $claimed, $merchant, $capability, $result, 'dispatch');
    }

    public function recover(ExecutionScope $scope, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        [$operation, $attempt] = $this->records($scope, $attemptRef);
        if ($attempt->state === AttemptState::Admitted) {
            // TxA committed but TxB never did. Only a normal active-session TxB may initiate.
            return $this->dispatch($scope, $attemptRef, $worker, $leaseUntil);
        }
        if (in_array($attempt->state, [AttemptState::Cancelled, AttemptState::Failed, AttemptState::Succeeded], true)) {
            return StoredOutcome::accepted(new ExecutionReceipt($attemptRef, $attemptRef, $operation->operationRef,
                $operation->binding->semanticDigest, $attempt->state->value, true, []));
        }
        $merchant = $this->merchants->historical($scope, $operation->rootOperationRef);
        [$driver, $capability] = $this->adapters->select($merchant, $operation->kind, $operation->requested->currency->code);
        $claim = $this->leases->claim($operation, $attempt, $worker, $leaseUntil);
        $assignedId = $capability->details->lookupByProviderId ? $this->store->providerOperationId($scope, $claim->attemptRef) : null;
        if (($assignedId === null && ! $capability->lookupByReference) || ($operation->kind === EffectKind::Refund && ! $capability->details->refundLookup)) {
            $result = new ProviderLookupResult(ProviderLookupState::Unsupported, null, null, null, 'original_lookup_requires_manual_recovery', null);
        } else {
            try {
                $credentials = $this->credentials->resolve($merchant, 'lookup');
                if ($credentials->purpose !== 'lookup' || $credentials->merchantAccountRef !== $merchant->merchantAccountRef || $credentials->expiresAt <= new DateTimeImmutable) {
                    throw new ProblemException(ProblemCode::LookupUnavailable, 'No valid original-account lookup credentials');
                }
                $result = $driver->lookup(new LookupRequest($operation->binding, $operation->operationRef, $claim->providerReference, $assignedId, $credentials));
            } catch (Exception) {
                $reason = $assignedId === null ? 'original_reference_lookup_unavailable' : 'original_assigned_id_lookup_unavailable';
                $result = new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, $reason, null);
            }
        }

        return $this->retain($operation, $claim, $merchant, $capability, $result, 'lookup', $assignedId);
    }

    /** @return array{OperationRecord,AttemptRecord} */
    private function records(ExecutionScope $scope, string $ref): array
    {
        $attempt = $this->store->attempt($scope, $ref) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown attempt within this scope');
        $operation = $this->store->operation($scope, $attempt->operationRef) ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Attempt operation unavailable');
        PaymentIdentity::select($this->callers->current(), $scope, $operation->binding->executionProfile);

        return [$operation, $attempt];
    }

    private function retain(OperationRecord $operation, AttemptRecord $claim, MerchantRevision $merchant, CapabilitySnapshot $capability, ProviderLookupResult $result, string $source, ?string $assignedId = null): StoredOutcome
    {
        $ref = 'transport:'.bin2hex(random_bytes(20));
        $retained = $this->journal->retain($operation, $claim, $merchant, $result, $source, false, $ref)->orThrow();
        $verified = $source === 'lookup' && $this->absence->valid($result->absence, $operation, $claim, $capability);
        if ($verified) {
            $ref = 'attestation:'.bin2hex(random_bytes(20));
            $retained = $this->journal->retain($operation, $claim, $merchant, $result, 'verified_absence', true, $ref)->orThrow();
        }
        $snapshot = $result->snapshot;
        $evidence = $result->evidence;
        $refs = $retained->body['evidenceRefs'] ?? [];
        $conflict = $evidence !== null && is_array($refs) && isset($refs[1]) && $refs[1] !== $evidence->evidenceRef;
        $matching = ! $conflict && $snapshot !== null && $evidence !== null && get_object_vars($evidence->scope) === get_object_vars($operation->binding->scope)
            && $evidence->providerId === $merchant->providerId && $evidence->merchantAccountRef === $merchant->merchantAccountRef
            && $snapshot->merchantAccountRef === $operation->binding->merchantAccountRef && $snapshot->operationRef === $operation->operationRef && $snapshot->kind === $operation->kind && $snapshot->currency === $operation->requested->currency->code
            && ($assignedId === null || ($snapshot->providerOperationId === $assignedId && $snapshot->providerReference === $claim->providerReference
                && $snapshot->bindingDigest === AuthorityClaims::bindingDigest($operation->binding)));
        // Material confirmation is delegated to the reducer. A known delivery can leave lookup scheduling only after durable custody.
        $state = $matching ? AttemptState::Pending : AttemptState::Unknown;
        $work = match (true) {
            $verified, $matching && $result->state === ProviderLookupState::Found
                && in_array($snapshot->normalizedStatus, ['succeeded', 'declined'], true) => WorkState::Processed,
            $conflict, $result->state === ProviderLookupState::Unsupported, $snapshot !== null && ! $matching => WorkState::Parked,
            default => WorkState::Due,
        };
        try {
            return $this->leases->finish($operation, $claim, $ref, $state, $work,
                $work === WorkState::Due ? ($result->retryAt ?? new DateTimeImmutable('+1 second')) : null, $result->reasonCode);
        } catch (ProblemException $e) {
            if (! in_array($e->problem, [ProblemCode::InstallationQuarantined, ProblemCode::RecoveryRequired, ProblemCode::AuthDenied], true)) {
                throw $e;
            }
        } catch (ModuleAdmissionRefused) {
            // A seal/module transition may deny any new financial root after response custody committed.
        }

        return $retained;
    }
}
