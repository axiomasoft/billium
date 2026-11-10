<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Participants;

use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Schedule\Contracts\CorrectionParticipants;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\CorrectionBasis;
use Billium\Schedule\Data\CorrectionReceipt;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Errors\OccurrenceProblems;
use Billium\Schedule\Internal\Locks\ScheduleLockRequests;
use Billium\Schedule\Internal\Operations\ScheduleOperations;
use Billium\Schedule\Internal\Payload;
use Billium\Schedule\Internal\Storage\OccurrenceStore;
use DateTimeImmutable;

final readonly class DatabaseCorrectionParticipants implements CorrectionParticipants
{
    public function __construct(private OperationRunner $runner, private ScheduleLockRequests $locks, private OccurrenceStore $store) {}

    public function registerCorrectionChild(TransactionContext $context, RegisterCorrectionChild $command): CorrectionReceipt
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
        $this->locks->assertHeld(context: $context, ref: $command->parentOccurrenceRef);
        $c = $command;
        $caller = $context->caller();
        if ($c->sellerRef !== $caller->sellerRef || $c->environment !== $caller->environment
            || $c->correctionRevision < 1 || $c->predecessorRevision !== ($c->correctionRevision === 1 ? null : $c->correctionRevision - 1)
            || (($c->taxCorrectionPolicyRef === null) !== ($c->taxCorrectionPolicyVersion === null))
            || $c->obligationBasisRef !== CorrectionBasis::derive(parentOccurrenceRef: $c->parentOccurrenceRef, correctionRevision: $c->correctionRevision)) {
            OccurrenceProblems::refuse('correction.conflict');
        }
        foreach ([$c->predecessorRatedBasisRef, $c->sourceFrontierDigest, $c->reasonCode, $c->obligationBasisRef] as $ref) {
            if ($ref === '' || strlen($ref) > 200) {
                OccurrenceProblems::refuse('correction.conflict');
            }
        }
        $parent = $this->store->occurrence(ref: $c->parentOccurrenceRef, context: $context);
        if ($parent === null || $parent->state !== 'obligated'
            || new DateTimeImmutable(Payload::text($parent->period_start)) != $c->originalPeriodStart
            || new DateTimeImmutable(Payload::text($parent->period_end)) != $c->originalPeriodEnd) {
            OccurrenceProblems::refuse('correction.parent_invalid');
        }
        $digest = Canonical::digest(ScheduleOperations::payload($c));
        // Replay precedes frontier CAS: its original expected head has necessarily advanced.
        $existing = $this->store->child(context: $context, parent: $c->parentOccurrenceRef, frontier: $c->sourceFrontierDigest);
        if ($existing !== null) {
            if ($existing->registered_digest !== $digest) {
                OccurrenceProblems::refuse('correction.conflict');
            }

            return new CorrectionReceipt(correctionRef: Payload::text($existing->correction_ref), parentOccurrenceRef: $c->parentOccurrenceRef,
                correctionRevision: Payload::integer($existing->correction_revision), created: false, registeredDigest: $digest);
        }
        $head = $this->store->correctionHead(context: $context, parent: $c->parentOccurrenceRef);
        if ($head->head_revision !== $c->expectedHeadRevision || $head->head_frontier_digest !== $c->expectedHeadFrontierDigest
            || $c->correctionRevision !== $c->expectedHeadRevision + 1) {
            OccurrenceProblems::refuse('correction.frontier_stale');
        }
        if ($c->correctionRevision === 1 && $parent->rated_basis_ref !== $c->predecessorRatedBasisRef) {
            OccurrenceProblems::refuse('correction.frontier_stale');
        }
        $ref = 'corr_'.substr(hash('sha256', $c->parentOccurrenceRef.'#'.$c->correctionRevision), 0, 48);
        $this->store->registerChild(context: $context, c: $c, parent: $parent, ref: $ref, digest: $digest);

        return new CorrectionReceipt(correctionRef: $ref, parentOccurrenceRef: $c->parentOccurrenceRef,
            correctionRevision: $c->correctionRevision, created: true, registeredDigest: $digest);
    }
}
