<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Work;

use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\Services\CollectionAdmission;
use Billium\Collection\Internal\Services\DatabaseCollectionParticipants;
use Billium\Collection\Internal\Services\DatabaseDunningCaseService;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionEvidenceQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Enums\AttemptState;
use Closure;
use DateTimeImmutable;
use Packman\Attributes\Internal;

/** D54/D56/D57/D63: replay the saved admission, look up the original attempt and project reduced, applied facts. */
#[Internal]
final readonly class CollectionRecoveryWorker
{
    public function __construct(
        private CollectionWork $work,
        private DunningQueries $queries,
        private DatabaseDunningCaseService $cases,
        private DatabaseCollectionParticipants $participants,
        private RootPaymentCommands $payments,
        private ExecutionStore $execution,
        private AttemptWorker $attempts,
        private MerchantRegistry $merchants,
        private ExecutionEvidenceQueries $evidence,
    ) {}

    /**
     * @param  (Closure(MaterialSnapshot): ?DeclineCategory)|null  $classifyDecline
     * @return array{done: int, due: int, parked: int, stale: int}
     */
    public function run(ExecutionScope $scope, string $profile, int $limit = 20, int $leaseSeconds = 30, ?Closure $classifyDecline = null): array
    {
        $batch = $this->work->claim(self::financialScope($scope, $profile), $limit, $leaseSeconds);
        $counts = ['done' => 0, 'due' => 0, 'parked' => $batch['exhausted'], 'stale' => 0];
        foreach ($batch['claims'] as $claim) {
            $counts[$this->process($scope, $profile, $claim, $classifyDecline)]++;
        }

        return $counts;
    }

    /**
     * Reconcile a parked step after execution and ledger have applied new facts.
     *
     * @param  (Closure(MaterialSnapshot): ?DeclineCategory)|null  $classifyDecline
     */
    public function reconcileParked(ExecutionScope $scope, string $profile, string $stepRef, ?Closure $classifyDecline = null): bool
    {
        $step = $this->queries->findStep(new CollectionScope($scope->sellerRef, $scope->environment), $stepRef);
        if ($step === null) {
            return false;
        }
        $batch = $this->work->claim(self::financialScope($scope, $profile), 1, 30, $step->id);

        return isset($batch['claims'][0]) && $this->process($scope, $profile, $batch['claims'][0], $classifyDecline, factsOnly: true) === 'done';
    }

    /**
     * @param  (Closure(MaterialSnapshot): ?DeclineCategory)|null  $classifyDecline
     * @return 'done'|'due'|'parked'|'stale'
     */
    private function process(ExecutionScope $scope, string $profile, CollectionWorkClaim $claim, ?Closure $classifyDecline, bool $factsOnly = false): string
    {
        if ($claim->until <= new DateTimeImmutable) {
            return 'stale';
        }
        $auth = $claim->authorization;
        $collection = new CollectionScope($scope->sellerRef, $scope->environment);
        $operationRef = RowCast::str($auth->operation_ref, 'operation_ref');
        $ref = RowCast::str($auth->authorization_ref, 'authorization_ref');
        $lookupUntil = null;
        try {
            $step = $this->step($collection, $claim);
            if (in_array($step->status, [CollectionStepStatus::Cancelled, CollectionStepStatus::FailedTerminal, CollectionStepStatus::Succeeded], true)) {
                return $this->finish($claim, 'done');
            }
            $operation = $this->execution->operation($scope, $operationRef);
            $attempt = $this->execution->attemptForOperation($scope, $operationRef);
            if (! $factsOnly && $step->status === CollectionStepStatus::Authorized && $attempt?->startedAt === null) {
                if ($claim->mode !== FinancialMode::Active) {
                    return $this->finish($claim, 'parked', 'active_dispatch_required');
                }
                if ($auth->financial_epoch !== $claim->epoch) {
                    return $this->finish($claim, 'parked', 'collection_epoch_changed');
                }
                try {
                    $this->payments->admit($scope, CollectionAdmission::decodePayment(CollectionAdmission::paymentPayload($auth)))->orThrow();
                    $attempt = $this->execution->attemptForOperation($scope, $operationRef)
                        ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Execution admission has no attempt');
                    if ($attempt->startedAt === null) {
                        $this->attempts->dispatch($scope, $attempt->attemptRef, self::workerRef($claim), $claim->until)->orThrow();
                    }
                } catch (ProblemException $e) {
                    if ($e->problem === ProblemCode::IdempotencyConflict) {
                        return $this->finish($claim, 'parked', $e->problem->value);
                    }
                    if ($e->problem !== ProblemCode::OperationFinancialHold) {
                        throw $e;
                    }
                    $this->participants->closeRevoked($scope, $ref);

                    return $this->finish($claim, 'done', 'guard_refused');
                }
            } elseif (! $factsOnly && $operation !== null && $attempt?->startedAt !== null
                && ! in_array($attempt->state, [AttemptState::Cancelled, AttemptState::Failed, AttemptState::Succeeded], true)) {
                $lookupUntil = $this->lookupDeadline($operation, $attempt);
                if ($lookupUntil === null || $lookupUntil <= new DateTimeImmutable) {
                    $this->markUnknown($collection, $claim, $attempt);

                    return $this->finish($claim, 'parked', 'lookup_window_unavailable');
                }
                // Execution retains and enqueues observed evidence even if this worker loses its lease while on the network.
                $this->attempts->recover($scope, $attempt->attemptRef, self::workerRef($claim), $claim->until)->orThrow();
            }

            $operation = $this->execution->operation($scope, $operationRef);
            $attempt = $this->execution->attemptForOperation($scope, $operationRef);
            if ($operation !== null && in_array($operation->state, ['failed', 'succeeded'], true)) {
                return $this->project($scope, $profile, $claim, $collection, $operation, $classifyDecline);
            }
            if (! $factsOnly && $attempt?->state === AttemptState::Unknown) {
                $this->markUnknown($collection, $claim, $attempt);
            }

            return $factsOnly ? $this->finish($claim, 'parked', 'outcome_unresolved') : $this->retry($claim, 'outcome_unresolved', $lookupUntil);
        } catch (ProblemException $e) {
            return match ($e->problem) {
                ProblemCode::InstallationQuarantined => 'stale',
                ProblemCode::CapabilityUnsupported => $this->finish($claim, 'parked', $e->problem->value),
                ProblemCode::RecoveryRequired, ProblemCode::LookupUnavailable, ProblemCode::OperationNotCancellable, ProblemCode::IdempotencyConflict => $factsOnly ? $this->finish($claim, 'parked', $e->problem->value) : $this->retry($claim, $e->problem->value, $lookupUntil),
                default => throw $e,
            };
        }
    }

    /**
     * @param  (Closure(MaterialSnapshot): ?DeclineCategory)|null  $classifyDecline
     * @return 'done'|'due'|'parked'|'stale'
     */
    private function project(ExecutionScope $scope, string $profile, CollectionWorkClaim $claim, CollectionScope $collection,
        OperationRecord $operation, ?Closure $classifyDecline): string
    {
        $snapshot = $this->evidence->material($scope, $profile, $operation->operationRef, $operation->revision);
        $category = $snapshot?->normalizedStatus === 'declined' && $classifyDecline !== null ? $classifyDecline($snapshot) : null;
        if ($snapshot === null || ($operation->state === 'failed' && $category === null)) {
            return $this->finish($claim, 'parked', 'terminal_evidence_or_classification_required');
        }

        $step = $this->step($collection, $claim);
        $target = $operation->state === 'succeeded' ? CollectionStepStatus::Succeeded : CollectionStepStatus::FailedTerminal;
        if ($step->status !== $target) {
            $this->cases->transitionClaimedStep(new TransitionCollectionStep(
                CollectionAdmission::commandKey((string) $step->stepAuthorizationRef, $target->value.':'.$operation->revision.':'.$step->stepRevision),
                $collection, $step->caseRef, $step->stepRef, $target, $step->stepRevision, $snapshot->observedAt,
                declineCategory: $category, outcomeEvidenceDigest: CanonicalJson::digest([
                    $operation->operationRef, $operation->revision, $snapshot->materialDigest, $snapshot->evidenceRefs,
                ]),
            ), $claim, $operation->revision);
        }

        return $this->finish($claim, 'done');
    }

    private function markUnknown(CollectionScope $scope, CollectionWorkClaim $claim, AttemptRecord $attempt): void
    {
        $step = $this->step($scope, $claim);
        if ($step->status === CollectionStepStatus::Dispatched && $attempt->startedAt !== null) {
            $this->cases->transitionClaimedStep(new TransitionCollectionStep(
                CollectionAdmission::commandKey((string) $step->stepAuthorizationRef, 'unknown:'.$step->stepRevision),
                $scope, $step->caseRef, $step->stepRef, CollectionStepStatus::Unknown, $step->stepRevision, $attempt->startedAt,
                declineReason: 'original_outcome_unknown',
            ), $claim);
        }
    }

    private function lookupDeadline(OperationRecord $operation, AttemptRecord $attempt): ?DateTimeImmutable
    {
        $binding = $operation->binding;
        $merchant = $this->merchants->historical($binding->scope, $binding->rootOperationRef);
        $capability = $this->merchants->capabilities($merchant->capabilitySnapshotRef);
        $seconds = $capability->details->lookupRetentionSeconds;
        if ($seconds === null || $capability->digest !== $binding->capabilityDigest
            || (! $capability->lookupByReference && (! $capability->details->lookupByProviderId
                || $this->execution->providerOperationId($binding->scope, $attempt->attemptRef) === null))) {
            return null;
        }

        return $attempt->startedAt?->modify('+'.$seconds.' seconds');
    }

    private function step(CollectionScope $scope, CollectionWorkClaim $claim): CollectionStepView
    {
        return $this->queries->findStep($scope, RowCast::str($claim->authorization->step_ref, 'step_ref'))
            ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection work has no step');
    }

    /** @return 'due'|'parked'|'stale' */
    private function retry(CollectionWorkClaim $claim, string $reason, ?DateTimeImmutable $lookupUntil): string
    {
        $delay = min(60, 2 ** min(6, $claim->fence));
        $next = new DateTimeImmutable('+'.$delay.' seconds');
        $deadline = $lookupUntil ?? RowCast::utcDateTime($claim->authorization->recovery_deadline_at, 'recovery_deadline_at');

        return new DateTimeImmutable >= $deadline
            ? $this->finish($claim, 'parked', $reason) : $this->finish($claim, 'due', $reason, min($next, $deadline));
    }

    /**
     * @template T of 'done'|'due'|'parked'
     *
     * @param  T  $status
     * @return T|'stale'
     */
    private function finish(CollectionWorkClaim $claim, string $status, ?string $reason = null, ?DateTimeImmutable $next = null): string
    {
        try {
            return $this->work->finish($claim, $status, $next, $reason) ? $status : 'stale';
        } catch (ProblemException $e) {
            if ($e->problem === ProblemCode::InstallationQuarantined || $e->problem === ProblemCode::RecoveryRequired) {
                return 'stale';
            }

            throw $e;
        }
    }

    private static function workerRef(CollectionWorkClaim $claim): string
    {
        return 'collection:'.$claim->stepId.':'.$claim->fence;
    }

    private static function financialScope(ExecutionScope $scope, string $profile): FinancialScope
    {
        return new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $profile);
    }
}
