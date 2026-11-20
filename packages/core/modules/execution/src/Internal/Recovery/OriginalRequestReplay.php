<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Recovery;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\OriginalReplayAuthority;
use Billium\Execution\Contracts\QualifiedReplayDriver;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ReplayAuthorization;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Admission\CollectionChecks;
use Billium\Execution\Internal\Admission\ExecutionReadinessGuard;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Authorization\AuthorizationPolicy;
use Billium\Execution\Internal\Dispatch\AttemptLeases;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Platform\Contracts\Installation\Installation;
use Closure;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final readonly class OriginalRequestReplay
{
    /** @param Closure():?OriginalReplayAuthority $replayAuthority */
    public function __construct(private ExecutionStore $store, private CallerContext $callers, private MerchantRegistry $merchants,
        private ContinuityMerchantCredentials $credentials, private QualifiedAdapter $adapters, private TransportBoundary $boundary,
        private AttemptLeases $leases, private DispatchAuthority $authority, private AttemptJournal $journal, private ExecutionReadinessGuard $readiness,
        private OperationRunner $runner, private Installation $installation, private CollectionChecks $collection, private MerchantActionGate $merchantActions,
        private DatabaseManager $databases, private Closure $replayAuthority) {}

    /** @param Closure(OperationRecord,AttemptRecord,MerchantRevision,CapabilitySnapshot,ProviderLookupResult,string,?string):StoredOutcome $retain */
    public function replay(ExecutionScope $scope, string $attemptRef, string $worker, DateTimeImmutable $leaseUntil, string $approvalRef, Closure $retain): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        $caller = $this->callers->current();
        if ($approvalRef === '' || ! in_array('execution.replay', $caller->permissions, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Original request replay requires a separate operator approval');
        }
        $attempt = $this->store->attempt($scope, $attemptRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Original attempt unavailable');
        $op = $this->store->operation($scope, $attempt->operationRef) ?? throw self::refused('Historical operation unavailable');
        $binding = $op->binding;
        PaymentIdentity::select($caller, $scope, $binding->executionProfile);
        if ($binding->origin === null || $attempt->startedAt === null || ! in_array($attempt->state, [AttemptState::Dispatching, AttemptState::Pending, AttemptState::Unknown], true)) {
            throw self::refused('Replay requires an unresolved first dispatch');
        }
        $merchant = $this->merchants->historical($scope, $op->rootOperationRef);
        [$driver, $cap] = $this->adapters->select($merchant, $op->kind, $op->requested->currency->code);
        $assigned = $cap->details->lookupByProviderId ? $this->store->providerOperationId($scope, $attemptRef) : null;
        if (($assigned === null && ! $cap->lookupByReference) || ($op->kind === EffectKind::Refund && ! $cap->details->refundLookup)) {
            throw self::refused('Original lookup is unavailable');
        }
        $credentials = $this->credentials->resolve($merchant, 'lookup');
        $claim = $this->leases->claim($op, $attempt, $worker, $leaseUntil);
        try {
            $lookup = $driver->lookup(new LookupRequest($binding, $op->operationRef, $attempt->providerReference, $assigned, $credentials));
        } catch (Exception) {
            $lookup = new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'original_lookup_unavailable', null);
        }
        if (! in_array($lookup->state, [ProviderLookupState::Ambiguous, ProviderLookupState::Unavailable], true) || $lookup->evidence !== null) {
            return $retain($op, $claim, $merchant, $cap, $lookup, 'lookup', $assigned);
        }
        $ref = 'transport:'.bin2hex(random_bytes(20));
        $this->journal->retain($op, $claim, $merchant, $lookup, 'lookup', false, $ref)->orThrow();
        try {
            $this->readiness->assertReady($binding, $op->kind, $op->requested);
            $host = ($this->replayAuthority)();
            $ttl = $cap->details->idempotencyRetentionSeconds;
            if (! $driver instanceof QualifiedReplayDriver || $host === null || ! $cap->details->initiateWithIdempotency || $ttl === null) {
                throw self::refused('Historical driver/authority cannot replay a qualified original request');
            }
            // The irreversible first-dispatch timestamp is a conservative lower bound on provider retention.
            $window = $attempt->startedAt->modify('+'.$ttl.' seconds');
            if ($window <= new DateTimeImmutable) {
                throw self::refused('Original provider retention window ended');
            }
            try {
                $original = $driver->originalRequest($binding, $attempt);
            } catch (Exception) {
                throw self::refused('Historical first-egress archive unavailable');
            }
            if ($original === null) {
                throw self::refused('Historical first-egress archive is unavailable');
            }
            // A completed lease clears the local ticket. The independent host still verifies the original first-egress record.
            if ($attempt->dispatchPermitRef !== null && $original->originalEgressRef !== $attempt->dispatchPermitRef) {
                throw self::refused('Archive does not identify the first dispatch ticket');
            }
            if (get_object_vars($original->scope) !== get_object_vars($scope)
                || $original->attemptRef !== $attemptRef || $original->operationRef !== $op->operationRef || $original->providerReference !== $attempt->providerReference) {
                throw self::refused('Archive identifies a different original attempt');
            }
            if ($original->providerId !== $merchant->providerId || $original->merchantAccountRef !== $binding->merchantAccountRef
                || $original->driverId !== $binding->driverId || $original->contractVersion !== $binding->driverContractVersion) {
                throw self::refused('Archive identifies a different historical merchant or adapter');
            }
            $action = match ($op->kind) {
                EffectKind::Authorize, EffectKind::Charge => 'new',
                default => $op->kind->value,
            };
            $outgoing = $this->credentials->resolve($merchant, $action);
            $session = $this->installation->currentSession($scope->installationRef) ?? throw self::refused('No current adopted owner');
            $request = new DispatchRequest($scope, $op->operationRef, $attemptRef, $attempt->providerReference, $op->kind, $op->requested,
                AuthorityClaims::bindingDigest($binding), $binding->capabilityDigest, $session->ownerSessionRef, $session->epoch,
                $session->authorityEpoch, $session->deploymentGeneration, $session->attachmentGeneration, $claim->leaseFence, 'billium.psp_dispatch.v1');
            try {
                $ticket = $this->authority->prepare($request);
                $approval = $host->authorize($approvalRef, $ticket, $original, $window);
            } catch (ProblemException $error) {
                throw $error;
            } catch (Exception) {
                throw self::refused('Replay writer or separate host approval unavailable');
            }
            if ($approval->approvalRef !== $approvalRef || $approval->writerTicketRef !== $ticket->ticketRef || $approval->requestDigest !== $original->digest()
                || $approval->expiresAt <= new DateTimeImmutable) {
                throw self::refused('Host approval does not bind this original request and writer');
            }
            $decision = $this->commit($op, $claim, $ticket, $approval, $action, $outgoing, $window);
            $current = new DriverRequest($decision, $binding, $cap, $op->requested, $attempt->providerReference, $outgoing);
            $this->authority->verifyReplayEgress($ticket, $current, $approval);
        } catch (Throwable $e) {
            $this->leases->finish($op, $claim, $ref, AttemptState::Unknown, WorkState::Due, new DateTimeImmutable('+1 second'), 'original_replay_not_sent');

            throw $e;
        }
        try {
            $result = $host->invoke($approval, $ticket, $original, $current, static fn (): ProviderLookupResult => $driver->replayOriginalWithinWindow($original, $outgoing));
        } catch (Exception) {
            $result = new ProviderLookupResult(ProviderLookupState::Unavailable, null, null, null, 'original_replay_outcome_unknown', null);
        }

        return $retain($op, $claim, $merchant, $cap, $result, 'original_replay', $assigned);
    }

    private function commit(OperationRecord $op, AttemptRecord $claim, DispatchTicket $ticket, ReplayAuthorization $approval, string $action,
        ResolvedCredentials $credentials, DateTimeImmutable $window): DispatchDecision
    {
        $binding = $op->binding;
        $scope = $binding->scope;
        $plan = $this->collection->plan(AuthorityClaims::collectionPermitAuthorizationRef($binding, $op->kind), ExecutionPhase::Dispatch, $scope);
        $class = match ($op->kind) {
            EffectKind::Charge, EffectKind::Authorize => $plan->operationClass ?? ModuleOperationClass::NewIntent,
            EffectKind::Capture, EffectKind::VoidAuthorization => ModuleOperationClass::FrozenIntentCompletion,
            EffectKind::Refund => ModuleOperationClass::OriginalOwnerRefund,
        };
        $spec = new OperationSpec('execution.original_replay', $scope->sellerRef, $scope->environment, FinancialMode::Active, $class, $this->collection->modules($plan),
            commitKind: 'execution.original_replay', financialScope: PaymentIdentity::select($this->callers->current(), $scope, $binding->executionProfile));
        $decision = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($op, $claim, $ticket, $approval, $action, $credentials, $window, $scope, $plan, &$decision): ExecutionReceipt {
            if ($this->store->lockNamespace($ctx, $scope)->state !== NamespaceState::Open) {
                throw self::refused('Original replay requires an open, reconciled execution namespace');
            }
            $this->merchantActions->lock($ctx, $scope, $op->binding->merchantRevisionRef, $action, $credentials);
            $locks = $this->store->plan($scope, $op->operationRef, context: $ctx);
            $this->collection->validate($ctx, $plan, $locks, $op->operationRef, $op->binding->semanticDigest);
            $this->store->lock($ctx, $locks);
            if ($this->store->capacity($ctx, $op->rootOperationRef)->financialHold) {
                throw new ProblemException(ProblemCode::OperationFinancialHold, 'Original root has an active financial hold');
            }
            if ($op->operationRef !== $op->rootOperationRef) {
                $reservation = $this->store->reservation($ctx, PaymentIdentity::reference($scope, $op->operationRef, 'reservation'));
                if ($reservation === null || $reservation->state !== ReservationState::Held || $reservation->kind !== $op->kind
                    || $reservation->operationRef !== $op->operationRef || $reservation->rootOperationRef !== $op->rootOperationRef || $reservation->requested->toWire() !== $op->requested->toWire()) {
                    throw self::refused('Original reservation is no longer held');
                }
            }
            $base = $this->collection->permit($ctx, $plan, $this->authority->validateReplay($ctx, $ticket, $op, $claim));
            $deadline = min($base->requestDeadlineAt ?? throw self::refused('Writer deadline unavailable'), $window, $approval->expiresAt, $credentials->expiresAt);
            if (in_array($op->kind, [EffectKind::Capture, EffectKind::VoidAuthorization], true)) {
                $auth = $this->store->authorization($ctx, $op->rootOperationRef);
                AuthorizationPolicy::assertOpen($auth, new DateTimeImmutable);
                if ($auth?->providerExpiresAt !== null) {
                    $deadline = min($deadline, $auth->providerExpiresAt);
                }
            }
            if ($deadline <= new DateTimeImmutable || $claim->startedAt === null) {
                throw self::refused('Replay permission expired before commit');
            }
            $decision = new DispatchDecision($base->operationRef, $base->attemptRef, $base->leaseFence, $base->sessionEpoch, $base->ticketRef,
                $base->egressFenceRef, $base->collectionDispatchPermitRef, $base->immutableRequestDigest, $deadline);
            $inserted = $this->databases->connection($ctx->connectionName())->table('billium_execution_original_replay')->insertOrIgnore([
                'installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment,
                'intent_owner_namespace' => $scope->intentOwnerNamespace, 'approval_ref' => $approval->approvalRef, 'attempt_ref' => $claim->attemptRef,
                'operation_ref' => $op->operationRef, 'writer_ticket_ref' => $ticket->ticketRef, 'original_request_digest' => $approval->requestDigest,
                'original_started_at' => $claim->startedAt->format('Y-m-d H:i:s.uP'), 'window_ends_at' => $window->format('Y-m-d H:i:s.uP'),
                'deadline' => $deadline->format('Y-m-d H:i:s.uP'), 'lease_fence' => $claim->leaseFence]);
            if ($inserted !== 1) {
                throw self::refused('Replay authorization already committed');
            }

            return new ExecutionReceipt($approval->approvalRef, $claim->attemptRef, $op->operationRef, $op->binding->semanticDigest, 'replay_authorized', false, []);
        })->orThrow();

        return $decision ?? throw self::refused('Replay authorization did not commit');
    }

    private static function refused(string $reason): ProblemException
    {
        return new ProblemException(ProblemCode::RecoveryRequired, $reason);
    }
}
