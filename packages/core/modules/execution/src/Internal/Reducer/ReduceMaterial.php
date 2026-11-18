<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reducer;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\OutcomePublication;
use Billium\Execution\Data\AuthorizationRecord;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\MaterialChange;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Custody\VerifiedDelivery;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Publication\OutcomeEmitter;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ReduceMaterial
{
    public function __construct(private ExecutionStore $store, private CapacityParticipants $capacity, private OutcomePublication $publication, private OutcomeEmitter $conflicts, private MaterialBounds $bounds) {}

    public function apply(TransactionContext $ctx, VerifiedDelivery $delivery, ProviderWorkClaim $claim): ReductionResult
    {
        $s = $delivery->snapshot;
        $scope = $delivery->evidence->scope;
        $op = $this->store->operation($scope, $s->operationRef, $ctx) ?? throw new LogicException('Original operation disappeared');
        $root = $this->store->operation($scope, $op->rootOperationRef, $ctx) ?? throw new LogicException('Original root disappeared');
        $attempt = $this->store->attemptForOperation($scope, $op->operationRef, $ctx) ?? throw new LogicException('Original attempt disappeared');
        $current = $this->capacity->current($ctx, $root->operationRef);
        $prior = $this->store->lastObserved($ctx, $op->operationRef);
        $frontier = $this->store->latestRootObservation($ctx, $root->operationRef);
        $invalid = MaterialRules::invalid($s, $op);
        if ($s->authorizationExpiresAt !== null) {
            $established = $this->store->authorization($ctx, $root->operationRef)?->providerExpiresAt;
            if (! $delivery->capability->authorizationExpiryEvidence || $op->kind !== EffectKind::Authorize || $op->operationRef !== $root->operationRef
                || MaterialRules::state($s) !== 'succeeded' || ($established !== null && $established->format('U.u') !== $s->authorizationExpiresAt->format('U.u'))) {
                $invalid ??= 'authorization_expiry_conflict';
            }
        }
        $orderingFrontier = $this->store->rootOrderingFrontier($ctx, $root->operationRef, $s);
        $order = MaterialRules::order($s, $prior, $frontier);
        $journalOrder = MaterialRules::order($s, null, $orderingFrontier);
        if ($journalOrder !== null && in_array($order, [null, 'duplicate', 'stale'], true)) {
            $order = $journalOrder;
        }
        // A late child's separate effect can settle once only when an already applied root snapshot covers its totals.
        $olderRoot = $order === 'stale' && $op->operationRef !== $root->operationRef && MaterialRules::order($s, $prior, null) === null
            && MaterialRules::order($s, null, $frontier) === 'stale';
        if ($olderRoot) {
            $order = null;
        }
        if ($invalid !== null || ($order !== null && ! in_array($order, ['duplicate', 'stale'], true))) {
            return $this->conflict($ctx, $op, $claim, $s, $invalid ?? $order);
        }
        $namespace = ExecutionCodec::providerIdentityScope($delivery->merchant, $delivery->capability);
        $economic = MaterialRules::digest($s);
        $stable = $delivery->capability->evidenceMode === ProviderEvidenceMode::StableEffectId;
        if ($stable && MaterialRules::state($s) === 'succeeded' && $s->stableEffectId === null) {
            return $this->conflict($ctx, $op, $claim, $s, 'missing_stable_effect_identity');
        }
        if ($stable && $prior !== null && MaterialRules::state($prior) === 'succeeded' && MaterialRules::state($s) === 'succeeded'
            && $prior->stableEffectId !== $s->stableEffectId) {
            return $this->conflict($ctx, $op, $claim, $s, 'provider_effect_identity_changed');
        }
        $id = PaymentIdentity::digest(['operation' => $op->operationRef, 'revision' => $s->providerRevision,
            'unversionedDigest' => $s->providerRevision === null ? $economic : null]);
        $fact = self::fact($namespace, $id, $economic, ProviderEvidenceMode::CumulativeSnapshot, $s);
        if ($s->providerOperationId !== null && ! $this->store->bindProviderOperationId($ctx, $attempt, $fact)) {
            return $this->conflict($ctx, $op, $claim, $s, 'provider_operation_id_conflict');
        }
        $existing = $this->store->factByIdentity($ctx, $namespace, $id);
        if ($existing !== null && $existing->materialDigest !== $economic) {
            return $this->conflict($ctx, $op, $claim, $s, 'provider_revision_conflict');
        }
        if ($order === 'stale') {
            return new ReductionResult(ReductionDisposition::Stale, $op->operationRef, $op->revision, [$claim->evidenceRef], [], [], null);
        }
        if ($stable && MaterialRules::state($s) === 'succeeded') {
            $effectId = PaymentIdentity::digest(['kind' => $op->kind->value, 'stableEffectId' => $s->stableEffectId]);
            $effectDigest = PaymentIdentity::digest(['operation' => $op->operationRef, 'kind' => $op->kind->value, 'account' => $s->merchantAccountRef,
                'actual' => $s->operationAmount?->toWire(), 'state' => 'succeeded']);
            $effect = $this->store->factByIdentity($ctx, $namespace, $effectId);
            if (($effect !== null && $effect->materialDigest !== $effectDigest) || ($effect === null && ! $this->store->appendFact($ctx,
                self::fact($namespace, $effectId, $effectDigest, ProviderEvidenceMode::StableEffectId, $s)))) {
                return $this->conflict($ctx, $op, $claim, $s, 'provider_effect_identity_conflict');
            }
        }
        if ($existing === null && ! $this->store->appendFact($ctx, $fact)) {
            return $this->conflict($ctx, $op, $claim, $s, 'provider_fact_identity_conflict');
        }
        if ($order === 'duplicate' || $existing !== null) {
            return new ReductionResult(ReductionDisposition::Duplicate, $op->operationRef, $op->revision, [$claim->evidenceRef], [], [], null);
        }
        if ($s->operationAmount === null) {
            $next = MaterialRules::state($s) === 'pending' ? AttemptState::Pending : AttemptState::Unknown;
            if ($attempt->startedAt !== null && ! in_array($attempt->state, [AttemptState::Cancelled, AttemptState::Failed, AttemptState::Succeeded], true)) {
                $this->store->applyAttemptFact($ctx, $attempt, $fact, $next, new DateTimeImmutable('+1 minute'));
            }

            return new ReductionResult(ReductionDisposition::Applied, $op->operationRef, $op->revision, [$claim->evidenceRef], [], [], null);
        }
        $reservation = $this->store->reservationForOperation($ctx, $op->operationRef);
        [$reason, $overCapture, $overRefund] = $this->bounds->evaluate($ctx, $s, $op, $root, $current, $reservation, $olderRoot);
        $state = MaterialRules::state($s);
        if ($state === 'succeeded' && $s->operationAmount->minor < $op->requested->minor) {
            $partial = match ($op->kind) {
                EffectKind::Capture => $delivery->capability->partialCapture, EffectKind::Refund => $delivery->capability->partialRefund,
                EffectKind::VoidAuthorization => $delivery->capability->partialVoidAuthorization, default => false,
            };
            if (! $partial) {
                $reason ??= 'unexpected_partial_amount';
            }
        }
        $sameEffect = $prior !== null && MaterialRules::sameEffect($prior, $s);
        if ($attempt->startedAt === null && $state === 'succeeded') {
            $reason ??= 'unexpected_positive_before_dispatch';
        }
        if ($reason !== null) {
            $capacity = new CapacitySnapshot($current->rootOperationRef, $current->version + 1, $current->authorizedLimit, $current->confirmedCapture,
                $current->confirmedRefund, $current->releasedAuthorization, $current->heldCapture, $current->heldVoid, $current->heldRefund, true);
        } elseif ($reservation?->state === ReservationState::Held && in_array($state, ['succeeded', 'failed'], true)) {
            $capacity = $this->capacity->settle($ctx, $reservation->reservationRef, $s, null);
        } elseif ($olderRoot) {
            $capacity = $current;
        } else {
            $capacity = new CapacitySnapshot($current->rootOperationRef, $current->version + 1, $s->authorizedTotal, $s->capturedTotal, $s->refundedTotal,
                $s->voidedTotal, $current->heldCapture, $current->heldVoid, $current->heldRefund, false);
        }
        $ref = $reason === null ? null : self::discrepancy($s, $reason);
        $after = self::next($op, $s, $reason === null ? $state : 'held', $economic);
        $changes = [];
        if ($op->operationRef !== $root->operationRef && ! $olderRoot && ($frontier === null || $s->authorizedTotal->minor !== $frontier->authorizedTotal->minor || $s->capturedTotal->minor !== $frontier->capturedTotal->minor
            || $s->refundedTotal->minor !== $frontier->refundedTotal->minor || $s->voidedTotal->minor !== $frontier->voidedTotal->minor || $reason !== null)) {
            $rootState = $reason === null ? $root->state : 'held';
            $changes[] = new MaterialChange($root, self::next($root, $s, $rootState, $economic), $s, $capacity, $ref, [], [], $overCapture, $overRefund, $reason);
        }
        $change = new MaterialChange($op, $after, $s, $capacity, $ref, [], [], $overCapture, $overRefund, $reason);
        if ($op->operationRef === $root->operationRef || ! $sameEffect || $reason !== null) {
            $changes[] = $change;
        }
        if ($changes === []) {
            return new ReductionResult(ReductionDisposition::Duplicate, $op->operationRef, $op->revision, [$claim->evidenceRef], [], [], null);
        }
        if ($ref !== null) {
            $this->store->appendDiscrepancy($ctx, $ref, $change);
        }
        if (! $olderRoot && $root->kind === EffectKind::Authorize && $reason === null && $state === 'succeeded') {
            $this->authorization($ctx, $root, $s);
        }
        $commands = $events = [];
        foreach ($changes as $material) {
            $this->store->appendRevision($ctx, $material, $claim->workRef);
            $publication = $this->publication->emit($ctx, $material);
            array_push($commands, ...$publication->commandRefs);
            array_push($events, ...$publication->eventIds);
        }
        if ($attempt->startedAt !== null && ! in_array($attempt->state, [AttemptState::Cancelled, AttemptState::Failed, AttemptState::Succeeded], true)) {
            $next = match ($state) {
                'succeeded' => AttemptState::Succeeded, 'failed' => AttemptState::Failed, 'pending' => AttemptState::Pending, default => AttemptState::Unknown
            };
            $due = in_array($next, [AttemptState::Pending, AttemptState::Unknown], true) ? new DateTimeImmutable('+1 minute') : null;
            $this->store->applyAttemptFact($ctx, $attempt, $fact, $next, $due);
        }

        return new ReductionResult($reason === null ? ReductionDisposition::Applied : ReductionDisposition::HeldDiscrepancy,
            $op->operationRef, $sameEffect && $op->operationRef !== $root->operationRef && $reason === null ? $op->revision : $after->revision, [$claim->evidenceRef], $commands, $events, $ref);
    }

    private static function fact(string $namespace, string $identity, string $digest, ProviderEvidenceMode $mode, MaterialSnapshot $snapshot): ProviderFact
    {
        $ref = 'fact:'.substr(PaymentIdentity::digest(['namespace' => $namespace, 'identity' => $identity]), 7);

        return new ProviderFact($ref, $snapshot->operationRef, $namespace, $identity, $digest, $mode, $snapshot, $snapshot->evidenceRefs);
    }

    private function conflict(TransactionContext $ctx, OperationRecord $op, ProviderWorkClaim $claim, MaterialSnapshot $s, string $reason): ReductionResult
    {
        $ref = self::discrepancy($s, $reason);
        $published = $this->conflicts->emitQuarantineHold($ctx, $claim, $s, $ref, $reason);
        $current = $this->store->operation($op->binding->scope, $op->operationRef, $ctx) ?? throw new LogicException('Original operation disappeared');

        return new ReductionResult(ReductionDisposition::Quarantined, $op->operationRef, $current->revision, $s->evidenceRefs, $published->commandRefs, [], $ref);
    }

    private static function discrepancy(MaterialSnapshot $s, string $reason): string
    {
        return 'discrepancy:'.substr(PaymentIdentity::digest(['reason' => $reason, 'operation' => $s->operationRef,
            'revision' => $s->providerRevision, 'material' => MaterialRules::digest($s),
            ...($reason === 'provider_effect_identity_changed' || $reason === 'provider_effect_identity_conflict' ? ['effectId' => $s->stableEffectId] : []),
            ...($reason === 'provider_operation_id_conflict' ? ['providerOperationId' => $s->providerOperationId] : [])]), 7);
    }

    private static function next(OperationRecord $op, MaterialSnapshot $s, string $state, string $economic): OperationRecord
    {
        $auth = $op->authorizationRef;
        if ($op->kind === EffectKind::Authorize && $s->authorizedTotal->minor > 0 && $state !== 'held') {
            $auth ??= PaymentIdentity::reference($op->binding->scope, $op->rootOperationRef, 'authorization');
        }

        return new OperationRecord($op->id, $op->operationRef, $op->rootOperationRef, $op->kind, $op->binding, $op->requested, $op->revision + 1,
            $state, PaymentIdentity::digest(['material' => $economic, 'state' => $state, 'operation' => $op->operationRef]), $auth, $op->createdAt);
    }

    private function authorization(TransactionContext $ctx, OperationRecord $root, MaterialSnapshot $s): void
    {
        $prior = $this->store->authorization($ctx, $root->operationRef);
        $state = match (true) {
            $s->voidedTotal->minor === $s->authorizedTotal->minor && $s->voidedTotal->minor > 0 && $s->capturedTotal->minor === 0 => 'voided',
            $s->capturedTotal->minor === $s->authorizedTotal->minor => 'captured',
            $s->capturedTotal->minor > 0 => 'partially_captured', default => 'authorized',
        };
        if ($prior !== null && $prior->state === $state && $prior->authorized->toWire() === $s->authorizedTotal->toWire() && $prior->released->toWire() === $s->voidedTotal->toWire()
            && ($s->authorizationExpiresAt === null || $prior->providerExpiresAt?->format('U.u') === $s->authorizationExpiresAt->format('U.u'))) {
            return;
        }
        $this->store->applyAuthorization($ctx, new AuthorizationRecord($prior->authorizationRef ?? PaymentIdentity::reference($root->binding->scope, $root->operationRef, 'authorization'),
            $root->operationRef, $s->authorizedTotal, $s->voidedTotal, $prior->providerExpiresAt ?? $s->authorizationExpiresAt,
            $prior->expiryEvidenceRef ?? ($s->authorizationExpiresAt === null ? null : $s->evidenceRefs[0]), $state, ($prior->revision ?? 0) + 1,
            $s->voidedTotal->minor === 0 ? null : ($prior !== null && $prior->released->minor === $s->voidedTotal->minor && $prior->releaseEvidenceRef !== null ? $prior->releaseEvidenceRef : $s->evidenceRefs[0])), $prior->revision ?? 0);
    }
}
