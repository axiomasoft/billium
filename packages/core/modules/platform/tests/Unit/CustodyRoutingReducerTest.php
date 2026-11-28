<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Unit;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Internal\Feed\PartitionRow;
use Billium\Platform\Internal\Reducers\CustodyRoutingReducer;
use PHPUnit\Framework\TestCase;

final class CustodyRoutingReducerTest extends TestCase
{
    public function test_classify_arrival_holds_for_recovery_when_lineage_changed_or_held_collision(): void
    {
        $reducer = new CustodyRoutingReducer;
        $partition = $this->partition(accountedSequence: 5, sourceLineage: 'L1');

        $changedDecision = $reducer->classifyArrival('L1', 6, $partition, false, false, changed: true, heldCollision: false);
        self::assertSame('held_recovery', $changedDecision->status);
        self::assertNull($changedDecision->requireRecoveryReason);

        $collisionDecision = $reducer->classifyArrival('L1', 6, $partition, false, false, changed: false, heldCollision: true);
        self::assertSame('held_recovery', $collisionDecision->status);
        self::assertNull($collisionDecision->requireRecoveryReason);
    }

    public function test_classify_arrival_without_partition_parks_gap_or_holds_recovery(): void
    {
        $reducer = new CustodyRoutingReducer;

        $parked = $reducer->classifyArrival('L1', 2, null, sequenceSlotTaken: false, retiredNamespace: false, changed: false, heldCollision: false);
        self::assertSame('parked_gap', $parked->status);
        self::assertNull($parked->requireRecoveryReason);

        $slotTaken = $reducer->classifyArrival('L1', 2, null, sequenceSlotTaken: true, retiredNamespace: false, changed: false, heldCollision: false);
        self::assertSame('held_recovery', $slotTaken->status);
        self::assertNull($slotTaken->requireRecoveryReason);

        $retired = $reducer->classifyArrival('L1', 2, null, sequenceSlotTaken: false, retiredNamespace: true, changed: false, heldCollision: false);
        self::assertSame('held_recovery', $retired->status);
        self::assertNull($retired->requireRecoveryReason);
    }

    public function test_classify_arrival_with_partition_in_recovery_required_stays_held_without_new_reason(): void
    {
        $reducer = new CustodyRoutingReducer;
        $partition = $this->partition(accountedSequence: 5, sourceLineage: 'L1', state: 'recovery_required');

        $decision = $reducer->classifyArrival('L2', 1, $partition, true, false, false, false);
        self::assertSame('held_recovery', $decision->status);
        self::assertNull($decision->requireRecoveryReason);
    }

    public function test_classify_arrival_detects_lineage_change_and_sequence_regression_or_slot_collision(): void
    {
        $reducer = new CustodyRoutingReducer;
        $partition = $this->partition(accountedSequence: 5, sourceLineage: 'L1');

        $lineageChanged = $reducer->classifyArrival('L2', 6, $partition, false, false, false, false);
        self::assertSame('held_recovery', $lineageChanged->status);
        self::assertSame('lineage_changed', $lineageChanged->requireRecoveryReason);

        $atAccountedBoundary = $reducer->classifyArrival('L1', 5, $partition, false, false, false, false);
        self::assertSame('held_recovery', $atAccountedBoundary->status);
        self::assertSame('sequence_regressed', $atAccountedBoundary->requireRecoveryReason);

        $belowAccounted = $reducer->classifyArrival('L1', 4, $partition, false, false, false, false);
        self::assertSame('held_recovery', $belowAccounted->status);
        self::assertSame('sequence_regressed', $belowAccounted->requireRecoveryReason);

        $slotTaken = $reducer->classifyArrival('L1', 6, $partition, sequenceSlotTaken: true, retiredNamespace: false, changed: false, heldCollision: false);
        self::assertSame('held_recovery', $slotTaken->status);
        self::assertSame('sequence_regressed', $slotTaken->requireRecoveryReason);
    }

    public function test_classify_arrival_distinguishes_ready_next_sequence_from_future_gap(): void
    {
        $reducer = new CustodyRoutingReducer;
        $partition = $this->partition(accountedSequence: 5, sourceLineage: 'L1');

        $ready = $reducer->classifyArrival('L1', 6, $partition, false, false, false, false);
        self::assertSame('ready', $ready->status);
        self::assertNull($ready->requireRecoveryReason);

        $gap = $reducer->classifyArrival('L1', 7, $partition, false, false, false, false);
        self::assertSame('parked_gap', $gap->status);
        self::assertNull($gap->requireRecoveryReason);
    }

    public function test_should_extend_custody_and_classify_post_insert(): void
    {
        $reducer = new CustodyRoutingReducer;

        self::assertFalse($reducer->shouldExtendCustody(null, 'parked_gap'));
        self::assertFalse($reducer->shouldExtendCustody(10, 'held_recovery'));
        self::assertTrue($reducer->shouldExtendCustody(10, 'parked_gap'));
        self::assertTrue($reducer->shouldExtendCustody(10, 'ready'));

        $noPartition = $reducer->classifyPostInsert(42, 'parked_gap', null, financial: true, custodyOnly: false);
        self::assertNotNull($noPartition);
        self::assertSame(CustodyDisposition::ParkedGap, $noPartition->disposition);
        self::assertSame(42, $noPartition->inboxId);
        self::assertSame('parked_gap', $noPartition->status);

        $held = $reducer->classifyPostInsert(43, 'held_recovery', 10, financial: true, custodyOnly: false);
        self::assertNotNull($held);
        self::assertSame(CustodyDisposition::RecoveryRequired, $held->disposition);
        self::assertSame(43, $held->inboxId);
        self::assertSame('held_recovery', $held->status);

        $parkedWithPartition = $reducer->classifyPostInsert(44, 'parked_gap', 10, financial: true, custodyOnly: false);
        self::assertNotNull($parkedWithPartition);
        self::assertSame(CustodyDisposition::ParkedGap, $parkedWithPartition->disposition);
        self::assertNull($parkedWithPartition->drainPartitionId);

        $rawCustody = $reducer->classifyPostInsert(45, 'ready', 10, financial: false, custodyOnly: false);
        self::assertNotNull($rawCustody);
        self::assertSame(CustodyDisposition::Received, $rawCustody->disposition);
        self::assertSame(10, $rawCustody->drainPartitionId);

        $custodyOnly = $reducer->classifyPostInsert(46, 'ready', 10, financial: true, custodyOnly: true);
        self::assertNotNull($custodyOnly);
        self::assertSame(CustodyDisposition::Received, $custodyOnly->disposition);
        self::assertSame(10, $custodyOnly->drainPartitionId);

        $shouldApply = $reducer->classifyPostInsert(47, 'ready', 10, financial: true, custodyOnly: false);
        self::assertNull($shouldApply);
    }

    public function test_assert_duplicate_provenance_enforces_scope_and_digest_rules(): void
    {
        $reducer = new CustodyRoutingReducer;

        // Matching scopes (including both null) or unlinked prior with matching digest is allowed.
        $reducer->assertDuplicateProvenance(null, null, 'sha256:a', 'sha256:a');
        $reducer->assertDuplicateProvenance(5, 5, 'sha256:a', 'sha256:b');
        $reducer->assertDuplicateProvenance(null, 5, 'sha256:a', 'sha256:a');

        foreach ([[5, 6, 'sha256:a', 'sha256:a'], [5, null, 'sha256:a', 'sha256:a'], [null, 5, 'sha256:a', 'sha256:b']] as [$prior, $root, $d1, $d2]) {
            try {
                $reducer->assertDuplicateProvenance($prior, $root, $d1, $d2);
                self::fail('Expected ProblemException for conflicting provenance');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ObservationConflict, $e->problem);
                self::assertSame('Delivery identity already has different immutable provenance', $e->detail);
            }
        }
    }

    public function test_classify_duplicate_handles_conflict_drain_and_parked_dependency_retry(): void
    {
        $reducer = new CustodyRoutingReducer;

        $conflict = $reducer->classifyDuplicate(11, 'applied', 'rcpt:1', 'sha256:a', 'sha256:b', 99, null);
        self::assertTrue($conflict->recordConflict);
        self::assertSame(CustodyDisposition::Conflict, $conflict->result->disposition);
        self::assertSame(11, $conflict->result->inboxId);
        self::assertSame('applied', $conflict->result->status);
        self::assertSame('rcpt:1', $conflict->result->resultRef);

        $ready = $reducer->classifyDuplicate(12, 'ready', null, 'sha256:a', 'sha256:a', 99, null);
        self::assertFalse($ready->recordConflict);
        self::assertSame(CustodyDisposition::Duplicate, $ready->result->disposition);
        self::assertSame(99, $ready->result->drainPartitionId);
        self::assertNull($ready->result->retryInboxId);

        $parkedGap = $reducer->classifyDuplicate(13, 'parked_gap', null, 'sha256:a', 'sha256:a', 99, null);
        self::assertSame(99, $parkedGap->result->drainPartitionId);
        self::assertNull($parkedGap->result->retryInboxId);

        $parkedGapNoPartition = $reducer->classifyDuplicate(14, 'parked_gap', null, 'sha256:a', 'sha256:a', null, null);
        self::assertNull($parkedGapNoPartition->result->drainPartitionId);

        $parkedDepWithEarlier = $reducer->classifyDuplicate(15, 'parked_dependency', null, 'sha256:a', 'sha256:a', 99, 7);
        self::assertNull($parkedDepWithEarlier->result->drainPartitionId);
        self::assertSame(7, $parkedDepWithEarlier->result->retryInboxId);

        $parkedDepSelf = $reducer->classifyDuplicate(16, 'parked_dependency', null, 'sha256:a', 'sha256:a', 99, null);
        self::assertSame(16, $parkedDepSelf->result->retryInboxId);

        $parkedDepNoPartition = $reducer->classifyDuplicate(17, 'parked_dependency', null, 'sha256:a', 'sha256:a', null, 7);
        self::assertNull($parkedDepNoPartition->result->retryInboxId);
    }

    public function test_classify_disposition_maps_outcomes_and_refuses_auth_denied(): void
    {
        $reducer = new CustodyRoutingReducer;

        $applied = $reducer->classifyDisposition(21, HandlerOutcome::applied('rcpt:21'));
        self::assertSame(CustodyDisposition::Applied, $applied->disposition);
        self::assertSame(21, $applied->inboxId);
        self::assertSame('applied', $applied->status);
        self::assertSame('rcpt:21', $applied->resultRef);

        $parked = $reducer->classifyDisposition(22, HandlerOutcome::parkedDependency('operation_revision', 'inbox:5'));
        self::assertSame(CustodyDisposition::ParkedDependency, $parked->disposition);
        self::assertSame(22, $parked->inboxId);
        self::assertSame('parked_dependency', $parked->status);

        $quarantined = $reducer->classifyDisposition(23, HandlerOutcome::conflict(ProblemCode::ObservationConflict->value));
        self::assertSame(CustodyDisposition::Quarantined, $quarantined->disposition);
        self::assertSame(23, $quarantined->inboxId);
        self::assertSame('quarantined', $quarantined->status);

        try {
            $reducer->classifyDisposition(24, HandlerOutcome::conflict(ProblemCode::AuthDenied->value));
            self::fail('Expected ProblemException when handler reports AuthDenied');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
            self::assertSame('The delivery is outside the authenticated source scope', $e->detail);
        }
    }

    private function partition(int $accountedSequence, string $sourceLineage, string $state = 'active'): PartitionRow
    {
        return new PartitionRow(
            id: 1,
            channel: 'payment.observation',
            sourceAuthority: 'stripe',
            sourceLineage: $sourceLineage,
            sellerRef: 'seller-a',
            environment: 'test',
            partitionRef: 'p-1',
            generation: 1,
            custodySequence: $accountedSequence,
            accountedSequence: $accountedSequence,
            state: $state,
        );
    }
}
