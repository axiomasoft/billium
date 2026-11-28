<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Reducers;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\HandlerOutcomeKind;
use Billium\Platform\Internal\Feed\PartitionRow;
use Packman\Attributes\Internal;

/**
 * Pure deterministic custody routing and disposition reducer (0 I/O).
 */
#[Internal]
final readonly class CustodyRoutingReducer
{
    public function classifyArrival(
        string $envelopeSourceLineage,
        int $envelopeSourceSequence,
        ?PartitionRow $partition,
        bool $sequenceSlotTaken,
        bool $retiredNamespace,
        bool $changed,
        bool $heldCollision,
    ): CustodyArrivalDecision {
        if ($changed || $heldCollision) {
            return new CustodyArrivalDecision('held_recovery', null);
        }

        if ($partition === null) {
            return new CustodyArrivalDecision(
                ! $sequenceSlotTaken && ! $retiredNamespace ? 'parked_gap' : 'held_recovery',
                null,
            );
        }

        if ($partition->state === 'recovery_required') {
            return new CustodyArrivalDecision('held_recovery', null);
        }

        if ($partition->sourceLineage !== $envelopeSourceLineage) {
            return new CustodyArrivalDecision('held_recovery', 'lineage_changed');
        }

        if ($envelopeSourceSequence <= $partition->accountedSequence || $sequenceSlotTaken) {
            return new CustodyArrivalDecision('held_recovery', 'sequence_regressed');
        }

        return new CustodyArrivalDecision(
            $envelopeSourceSequence === $partition->accountedSequence + 1 ? 'ready' : 'parked_gap',
            null,
        );
    }

    public function shouldExtendCustody(?int $partitionId, string $status): bool
    {
        return $partitionId !== null && $status !== 'held_recovery';
    }

    public function classifyPostInsert(
        int $inboxId,
        string $status,
        ?int $partitionId,
        bool $financial,
        bool $custodyOnly,
    ): ?CustodyResult {
        if ($partitionId === null || $status === 'held_recovery') {
            return new CustodyResult(
                $status === 'held_recovery' ? CustodyDisposition::RecoveryRequired : CustodyDisposition::ParkedGap,
                $inboxId,
                $status,
            );
        }

        if ($status === 'parked_gap') {
            return new CustodyResult(CustodyDisposition::ParkedGap, $inboxId, $status);
        }

        if (! $financial || $custodyOnly) {
            return new CustodyResult(CustodyDisposition::Received, $inboxId, $status, null, $partitionId);
        }

        return null;
    }

    /**
     * @throws ProblemException billium.observation.conflict when delivery identity has different immutable provenance
     */
    public function assertDuplicateProvenance(
        ?int $priorScopeId,
        ?int $rootScopeId,
        string $existingDigest,
        string $envelopeDigest,
    ): void {
        if ($priorScopeId !== $rootScopeId && ($priorScopeId !== null || $existingDigest !== $envelopeDigest)) {
            throw new ProblemException(
                ProblemCode::ObservationConflict,
                'Delivery identity already has different immutable provenance',
            );
        }
    }

    public function classifyDuplicate(
        int $inboxId,
        string $existingStatus,
        ?string $existingResultRef,
        string $existingDigest,
        string $envelopeDigest,
        ?int $existingPartitionId,
        ?int $earlierParkedId,
    ): CustodyDuplicateDecision {
        if ($existingDigest !== $envelopeDigest) {
            return new CustodyDuplicateDecision(
                recordConflict: true,
                result: new CustodyResult(CustodyDisposition::Conflict, $inboxId, $existingStatus, $existingResultRef),
            );
        }

        $drainPartitionId = in_array($existingStatus, ['ready', 'parked_gap'], true) && $existingPartitionId !== null
            ? $existingPartitionId
            : null;
        $retryInboxId = $existingStatus === 'parked_dependency' && $existingPartitionId !== null
            ? ($earlierParkedId ?? $inboxId)
            : null;

        return new CustodyDuplicateDecision(
            recordConflict: false,
            result: new CustodyResult(
                CustodyDisposition::Duplicate,
                $inboxId,
                $existingStatus,
                $existingResultRef,
                $drainPartitionId,
                retryInboxId: $retryInboxId,
            ),
        );
    }

    /**
     * @throws ProblemException billium.auth.denied when the handler refuses due to source scope
     */
    public function classifyDisposition(int $inboxId, HandlerOutcome $outcome): CustodyResult
    {
        if ($outcome->kind === HandlerOutcomeKind::Conflict && $outcome->reference === ProblemCode::AuthDenied->value) {
            throw new ProblemException(ProblemCode::AuthDenied, 'The delivery is outside the authenticated source scope');
        }

        return match ($outcome->kind) {
            HandlerOutcomeKind::Applied => new CustodyResult(CustodyDisposition::Applied, $inboxId, 'applied', $outcome->reference),
            HandlerOutcomeKind::ParkedDependency => new CustodyResult(CustodyDisposition::ParkedDependency, $inboxId, 'parked_dependency'),
            HandlerOutcomeKind::Conflict => new CustodyResult(CustodyDisposition::Quarantined, $inboxId, 'quarantined'),
        };
    }
}
