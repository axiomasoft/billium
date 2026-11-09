<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Inbox;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Caller;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\InboxRow;
use Billium\Platform\Tests\PlatformTestCase;
use Closure;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/** Custody order, parked, recovery and handler dispositions of one source partition (PF-12, PF-05, REQ-FAIL-12/13/23). */
#[Group('PF-12')]
#[Group('PF-05')]
final class CustodyProcessorTest extends InboxTestCase
{
    public function test_first_delivery_opens_the_partition_and_applies_once(): void
    {
        $handler = new JournalHandler;
        $result = $this->custody($this->envelope(1), $handler);

        self::assertSame(CustodyDisposition::Applied, $result->disposition);
        self::assertSame('receipt:evt-L1-1', $result->resultRef);
        self::assertSame(1, $handler->calls);
        $row = $this->inbox('evt-L1-1');
        self::assertSame('applied', $row['status']);
        self::assertSame('receipt:evt-L1-1', $row['result_ref']);
        self::assertNotNull($row['applied_at']);
        $partition = $this->partitionRow(self::integer($row['partition_id']));
        self::assertSame([1, 1, 'active', 'L1', 1], [$partition['custody_sequence'], $partition['accounted_sequence'], $partition['state'], $partition['source_lineage'], $partition['generation']]);
    }

    public function test_a_duplicate_returns_the_same_result_ref_without_a_second_effect(): void
    {
        $handler = new JournalHandler;
        $first = $this->custody($this->envelope(1), $handler);
        $second = $this->custody($this->envelope(1), $handler);

        self::assertSame(CustodyDisposition::Duplicate, $second->disposition);
        self::assertSame($first->resultRef, $second->resultRef);
        self::assertSame($first->inboxId, $second->inboxId);
        self::assertSame(1, $handler->calls);
        self::assertSame(1, $this->rows('billium_inbox_message'));
        self::assertSame(['evt-L1-1'], $this->journal());
    }

    public function test_the_same_event_with_another_digest_is_preserved_apart_and_the_original_is_untouched(): void
    {
        $handler = new JournalHandler;
        $this->custody($this->envelope(1), $handler);
        $before = $this->inbox('evt-L1-1');

        $conflicting = $this->envelope(1, salt: '-changed');
        $result = $this->custody($conflicting, $handler);

        self::assertSame(CustodyDisposition::Conflict, $result->disposition);
        self::assertSame(ProblemCode::ObservationConflict, $result->problem());
        self::assertSame($before, $this->inbox('evt-L1-1'));
        self::assertSame(1, $handler->calls);
        $conflict = $this->row('SELECT * FROM billium_inbox_conflict');
        self::assertSame($before['id'], $conflict['inbox_id']);
        self::assertSame($conflicting->contentDigest, $conflict['conflicting_digest']);
        // The same conflicting delivery again adds nothing.
        $this->custody($conflicting, $handler);
        self::assertSame(1, $this->rows('billium_inbox_conflict'));
        // Negative: the conflict record is append-only.
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'UPDATE billium_inbox_conflict SET received_at = now()'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'DELETE FROM billium_inbox_conflict'));
    }

    public function test_a_gap_parks_and_the_arrival_of_the_missing_sequence_hands_the_drain_to_new_roots(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler;

        $parked = $this->custody($this->envelope(12), $handler);
        self::assertSame(CustodyDisposition::ParkedGap, $parked->disposition);
        self::assertSame(ProblemCode::ObservationParked, $parked->problem());
        self::assertSame('parked_gap', $this->inbox('evt-L1-12')['status']);
        self::assertSame(0, $handler->calls);
        self::assertSame([10, 10], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);

        $applied = $this->custody($this->envelope(11), $handler);
        self::assertSame(CustodyDisposition::Applied, $applied->disposition);
        // Exactly one economic event per root: 12 is in custody (contiguous) but not applied inline.
        self::assertSame(['evt-L1-11'], $this->journal());
        self::assertSame($id, $applied->drainPartitionId);
        self::assertSame([12, 11], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);

        $drained = $this->drainAll($id, $handler);
        self::assertCount(1, $drained);
        self::assertSame(['evt-L1-11', 'evt-L1-12'], $this->journal());
        self::assertSame('applied', $this->inbox('evt-L1-12')['status']);
        self::assertSame([12, 12], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);
    }

    public function test_without_a_partition_a_later_sequence_waits_in_custody(): void
    {
        $handler = new JournalHandler;
        $result = $this->custody($this->envelope(3), $handler);

        self::assertSame(CustodyDisposition::ParkedGap, $result->disposition);
        self::assertSame(0, $this->rows('billium_feed_partition'));
        $row = $this->inbox('evt-L1-3');
        self::assertSame(['parked_gap', null], [$row['status'], $row['partition_id']]);
        self::assertSame(0, $handler->calls);
    }

    public function test_opening_a_partition_attaches_its_pre_partition_gap_and_duplicate_can_drain_it(): void
    {
        $handler = new JournalHandler;
        $parked = $this->custody($this->envelope(2), $handler);
        self::assertSame(CustodyDisposition::ParkedGap, $parked->disposition);
        self::assertNull($this->inbox('evt-L1-2')['partition_id']);

        $first = $this->custody($this->envelope(1), $handler);
        $id = self::integer($this->inbox('evt-L1-1')['partition_id']);
        self::assertSame($id, $this->inbox('evt-L1-2')['partition_id']);
        self::assertSame($id, $first->drainPartitionId);
        self::assertSame([2, 1], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);
        self::assertSame(['evt-L1-1'], $this->journal());

        $duplicate = $this->custody($this->envelope(2), $handler);
        self::assertSame(CustodyDisposition::Duplicate, $duplicate->disposition);
        self::assertSame($parked->inboxId, $duplicate->inboxId);
        self::assertSame($id, $duplicate->drainPartitionId);
        self::assertCount(1, $this->drainAll($id, $handler));
        self::assertSame(['evt-L1-1', 'evt-L1-2'], $this->journal());
        self::assertSame(['applied', 'applied'], [$this->inbox('evt-L1-1')['status'], $this->inbox('evt-L1-2')['status']]);
        self::assertSame([2, 2], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);
        self::assertSame(2, $handler->calls);
    }

    public function test_opening_a_partition_leaves_other_namespaces_and_held_deliveries_unattached(): void
    {
        $handler = new JournalHandler;
        $others = [
            $this->envelope(2, event: 'other-lineage', lineage: 'L2'),
            $this->envelope(2, event: 'other-partition', partition: 'part-2'),
            $this->envelope(2, event: 'other-authority', authority: 'remote-billium'),
            $this->envelope(2, event: 'other-seller', seller: 'seller-b'),
            $this->envelope(2, event: 'other-environment', environment: 'live'),
        ];
        foreach ($others as $envelope) {
            $this->callers->set(new Caller(self::text($envelope->sellerRef), self::text($envelope->environment), self::INSTALLATION, 'actor-1', [self::text($envelope->sourceAuthority)]));
            self::assertSame(CustodyDisposition::ParkedGap, $this->custody($envelope, $handler)->disposition);
        }
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [self::AUTHORITY]));
        $this->custody($this->envelope(2), $handler);
        self::assertSame(CustodyDisposition::RecoveryRequired, $this->custody($this->envelope(2, event: 'forked'), $handler)->disposition);

        $this->custody($this->envelope(1), $handler);

        self::assertNotNull($this->inbox('evt-L1-2')['partition_id']);
        foreach ($others as $envelope) {
            $row = $this->inbox(self::text($envelope->eventId));
            self::assertSame(['parked_gap', null], [$row['status'], $row['partition_id']]);
        }
        self::assertSame(['held_recovery', null], [$this->inbox('forked')['status'], $this->inbox('forked')['partition_id']]);
    }

    public function test_an_existing_partition_reattaches_orphans_before_processing_a_new_delivery(): void
    {
        $handler = new JournalHandler;
        $this->custody($this->envelope(2), $handler);
        $this->custody($this->envelope(2, lineage: 'L2'), $handler);
        $this->custody($this->envelope(2, event: 'forked'), $handler);
        $id = $this->seedPartition(1);

        $this->custody($this->envelope(3), $handler);

        self::assertSame($id, $this->inbox('evt-L1-2')['partition_id']);
        self::assertSame([3, 1], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);
        self::assertSame(['parked_gap', null], [$this->inbox('evt-L2-2')['status'], $this->inbox('evt-L2-2')['partition_id']]);
        self::assertSame(['held_recovery', null], [$this->inbox('forked')['status'], $this->inbox('forked')['partition_id']]);
        self::assertCount(2, $this->drainAll($id, $handler));
        self::assertSame(['evt-L1-2', 'evt-L1-3'], $this->journal());
    }

    public function test_a_recovering_partition_leaves_orphan_gaps_unattached(): void
    {
        $handler = new JournalHandler;
        $this->custody($this->envelope(2), $handler);
        $id = $this->seedPartition(1, state: 'recovery_required');

        self::assertSame(CustodyDisposition::RecoveryRequired, $this->custody($this->envelope(3), $handler)->disposition);
        self::assertSame(CustodyDisposition::Idle, $this->drain($id, $handler)->disposition);

        self::assertSame(['parked_gap', null], [$this->inbox('evt-L1-2')['status'], $this->inbox('evt-L1-2')['partition_id']]);
        self::assertSame([1, 1], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);
        self::assertSame(0, $handler->calls);
    }

    public function test_a_regressed_sequence_requires_recovery_and_has_no_effect(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler;

        $result = $this->custody($this->envelope(9, event: 'evt-other-9'), $handler);

        self::assertSame(CustodyDisposition::RecoveryRequired, $result->disposition);
        self::assertSame(ProblemCode::RecoveryRequired, $result->problem());
        self::assertSame('recovery_required', $this->partitionRow($id)['state']);
        self::assertSame('held_recovery', $this->inbox('evt-other-9')['status']);
        self::assertSame(0, $handler->calls);
        // The tail is held, custody continues, nothing is applied.
        $tail = $this->custody($this->envelope(11), $handler);
        self::assertSame(CustodyDisposition::RecoveryRequired, $tail->disposition);
        self::assertSame('held_recovery', $this->inbox('evt-L1-11')['status']);
        self::assertSame([], $this->journal());
        self::assertSame(10, $this->partitionRow($id)['accounted_sequence']);
    }

    public function test_another_event_in_a_taken_sequence_slot_requires_recovery(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler;
        $this->custody($this->envelope(12), $handler);

        $result = $this->custody($this->envelope(12, event: 'evt-forked-12'), $handler);

        self::assertSame(CustodyDisposition::RecoveryRequired, $result->disposition);
        self::assertSame('recovery_required', $this->partitionRow($id)['state']);
        self::assertSame('parked_gap', $this->inbox('evt-L1-12')['status']);
        self::assertSame('held_recovery', $this->inbox('evt-forked-12')['status']);
    }

    public function test_a_changed_lineage_requires_recovery_without_effect(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler;

        $result = $this->custody($this->envelope(11, lineage: 'L2'), $handler);

        self::assertSame(CustodyDisposition::RecoveryRequired, $result->disposition);
        self::assertSame('recovery_required', $this->partitionRow($id)['state']);
        self::assertSame('held_recovery', $this->inbox('evt-L2-11')['status']);
        self::assertSame(['L1', 10], [$this->partitionRow($id)['source_lineage'], $this->partitionRow($id)['accounted_sequence']]);
        self::assertSame(0, $handler->calls);
        // Negative: a recovering partition never returns to active by a plain UPDATE.
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), "UPDATE billium_feed_partition SET state = 'active' WHERE id = {$id}"));
    }

    public function test_a_dependency_parked_delivery_advances_the_source_order_but_not_the_application(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler(['evt-L1-11' => HandlerOutcome::parkedDependency('payment', 'charge-1')]);

        $parked = $this->custody($this->envelope(11), $handler);
        self::assertSame(CustodyDisposition::ParkedDependency, $parked->disposition);
        $row = $this->inbox('evt-L1-11');
        self::assertSame(['parked_dependency', 'payment', 'charge-1', null], [$row['status'], $row['park_kind'], $row['park_ref'], $row['result_ref']]);
        self::assertSame(11, $this->partitionRow($id)['accounted_sequence']);

        // Pitfall: the partition is not blocked by the parked observation of another operation.
        $next = $this->custody($this->envelope(12, operation: 'op-2'), $handler);
        self::assertSame(CustodyDisposition::Applied, $next->disposition);
        self::assertSame(12, $this->partitionRow($id)['accounted_sequence']);
    }

    public function test_an_earlier_parked_revision_blocks_a_later_revision_of_the_same_operation_until_it_resolves(): void
    {
        $this->seedPartition(10);
        $handler = new JournalHandler(['evt-L1-11' => HandlerOutcome::parkedDependency('obligation', 'ob-1')]);
        $this->custody($this->envelope(11, revision: 1), $handler);

        $later = $this->custody($this->envelope(12, revision: 2), $handler);
        self::assertSame(CustodyDisposition::ParkedDependency, $later->disposition);
        $row = $this->inbox('evt-L1-12');
        self::assertSame(['parked_dependency', 'operation_revision'], [$row['status'], $row['park_kind']]);
        self::assertSame(['evt-L1-11'], $this->journal(), 'the later revision never reached the handler');

        $firstId = self::integer($this->inbox('evt-L1-11')['id']);
        $laterId = self::integer($row['id']);
        // The later one cannot be retried first.
        $blocked = $this->retry($laterId, $handler);
        self::assertSame($firstId, $blocked->retryInboxId);
        self::assertSame(['evt-L1-11'], $this->journal());

        // The dependency arrives: the earlier revision applies and points at the next one.
        $handler->script = [];
        $resolved = $this->retry($firstId, $handler);
        self::assertSame(CustodyDisposition::Applied, $resolved->disposition);
        self::assertSame($laterId, $resolved->retryInboxId);
        self::assertSame(CustodyDisposition::Applied, $this->retry($laterId, $handler)->disposition);
        self::assertSame(['evt-L1-11', 'evt-L1-11', 'evt-L1-12'], $this->journal());
        self::assertSame(['applied', 'applied'], [$this->inbox('evt-L1-11')['status'], $this->inbox('evt-L1-12')['status']]);
    }

    public function test_a_handler_conflict_quarantines_the_delivery_and_the_order_continues(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler(['evt-L1-11' => HandlerOutcome::conflict('billium.observation.conflict')]);

        $result = $this->custody($this->envelope(11), $handler);

        self::assertSame(CustodyDisposition::Quarantined, $result->disposition);
        self::assertSame(ProblemCode::ObservationConflict, $result->problem());
        self::assertSame(['quarantined', 'billium.observation.conflict'], [$this->inbox('evt-L1-11')['status'], $this->inbox('evt-L1-11')['last_error_class']]);
        self::assertSame(11, $this->partitionRow($id)['accounted_sequence']);
    }

    public function test_raw_custody_commits_without_economic_effect_even_after_a_seal(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler;
        $this->installation()->seal('seal-1', 1);

        $received = $this->custody($this->envelope(11), $handler, FinancialMode::RawCustody);

        self::assertSame(CustodyDisposition::Received, $received->disposition);
        self::assertSame(0, $handler->calls);
        self::assertSame('ready', $this->inbox('evt-L1-11')['status']);
        self::assertSame($id, $received->drainPartitionId);
        self::assertSame([11, 10], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);
        // Economic apply needs a current admitted session: the sealed one refuses it.
        try {
            $this->drain($id, $handler);
            self::fail('A sealed session admits no economic apply');
        } catch (ProblemException $refused) {
            self::assertSame(ProblemCode::RecoveryRequired, $refused->problem);
        }
        self::assertSame(0, $handler->calls);
        self::assertSame('ready', $this->inbox('evt-L1-11')['status']);
    }

    public function test_a_raw_custody_delivery_is_applied_by_a_later_financial_drain(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler;
        $this->custody($this->envelope(11), $handler, FinancialMode::RawCustody);

        $results = $this->drainAll($id, $handler);

        self::assertCount(1, $results);
        self::assertSame(['evt-L1-11'], $this->journal());
        self::assertSame('applied', $this->inbox('evt-L1-11')['status']);
    }

    public function test_a_handler_failure_rolls_back_custody_with_the_effect(): void
    {
        $id = $this->seedPartition(10);
        $failing = new class implements CustodyHandler
        {
            public function handle(TransactionContext $context, InboxRow $row, \stdClass $payload): HandlerOutcome
            {
                PlatformTestCase::connection($context->connectionName())->insert("INSERT INTO billium_test_journal (kind, ref, source_sequence) VALUES ('live', 'x', 11)");

                throw new RuntimeException('handler failed after its effect');
            }
        };
        try {
            $this->root(fn (TransactionContext $context) => $this->feed()->process($context, 'payment.observation', $this->envelope(11), $failing));
            self::fail('The failure must propagate');
        } catch (RuntimeException $failure) {
            self::assertSame('handler failed after its effect', $failure->getMessage());
        }
        self::assertSame(0, $this->rows('billium_inbox_message'));
        self::assertSame(0, $this->rows('billium_test_journal'));
        self::assertSame(10, $this->partitionRow($id)['accounted_sequence']);
    }

    public function test_a_wrong_status_cannot_be_forged_by_the_runtime_role(): void
    {
        $this->custody($this->envelope(1), new JournalHandler);
        $runtime = TestEnvironment::pdo();
        // An applied message is final, its identity immutable, and nothing is deleted.
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_inbox_message SET status = 'ready'"));
        self::assertNotNull(self::sqlState($runtime, "UPDATE billium_inbox_message SET content_digest = 'sha256:".str_repeat('0', 64)."'"));
        self::assertNotNull(self::sqlState($runtime, 'DELETE FROM billium_inbox_message'));
        self::assertNotNull(self::sqlState($runtime, 'DELETE FROM billium_feed_partition'));
        // Frontiers never regress.
        self::assertNotNull(self::sqlState($runtime, 'UPDATE billium_feed_partition SET accounted_sequence = 0'));
    }

    private function retry(int $inboxId, JournalHandler $handler): CustodyResult
    {
        $result = $this->root(fn (TransactionContext $context) => $this->feed()->retryDependency($context, $inboxId, $handler));

        return $result instanceof CustodyResult ? $result : self::fail('retry returned no result');
    }

    public function test_a_drain_rejects_ungranted_sources_without_changing_custody(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler;
        $this->custody($this->envelope(11), $handler, FinancialMode::RawCustody);
        $before = $this->inbox('evt-L1-11');
        $partition = $this->partitionRow($id);

        foreach ([['remote-billium'], []] as $authorities) {
            $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', $authorities));
            $this->assertAuthDenied(fn () => $this->drain($id, $handler));
            self::assertSame($before, $this->inbox('evt-L1-11'));
            self::assertSame($partition, $this->partitionRow($id));
            self::assertSame(0, $handler->calls);
        }

        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', [self::AUTHORITY]));
        self::assertSame(CustodyDisposition::Applied, $this->drain($id, $handler)->disposition);
    }

    public function test_a_retry_rejects_ungranted_sources_before_the_retry_budget_check(): void
    {
        config(['billium.inbox.retry_budget' => 1]);
        $id = $this->seedPartition(10);
        $handler = new JournalHandler(['evt-L1-11' => HandlerOutcome::parkedDependency('payment', 'charge-1')]);
        $parked = $this->custody($this->envelope(11), $handler);
        $before = $this->inbox('evt-L1-11');
        $partition = $this->partitionRow($id);

        foreach ([['remote-billium'], []] as $authorities) {
            $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', $authorities));
            $this->assertAuthDenied(fn () => $this->retry(self::integer($parked->inboxId), $handler));
            self::assertSame($before, $this->inbox('evt-L1-11'));
            self::assertSame($partition, $this->partitionRow($id));
            self::assertSame(1, $handler->calls);
        }
    }

    public function test_a_drain_does_not_quarantine_a_handler_authorization_denial(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler(['evt-L1-11' => HandlerOutcome::conflict(ProblemCode::AuthDenied->value)]);
        $this->custody($this->envelope(11), $handler, FinancialMode::RawCustody);
        $before = $this->inbox('evt-L1-11');
        $partition = $this->partitionRow($id);

        $this->assertAuthDenied(fn () => $this->drain($id, $handler));
        self::assertSame($before, $this->inbox('evt-L1-11'));
        self::assertSame($partition, $this->partitionRow($id));
        self::assertSame([], $this->journal());

        $handler->script = [];
        self::assertSame(CustodyDisposition::Applied, $this->drain($id, $handler)->disposition);
    }

    public function test_a_retry_does_not_quarantine_or_consume_attempts_on_handler_authorization_denial(): void
    {
        $id = $this->seedPartition(10);
        $handler = new JournalHandler(['evt-L1-11' => HandlerOutcome::parkedDependency('payment', 'charge-1')]);
        $parked = $this->custody($this->envelope(11), $handler);
        $before = $this->inbox('evt-L1-11');
        $partition = $this->partitionRow($id);
        $handler->script = ['evt-L1-11' => HandlerOutcome::conflict(ProblemCode::AuthDenied->value)];

        $this->assertAuthDenied(fn () => $this->retry(self::integer($parked->inboxId), $handler));
        self::assertSame($before, $this->inbox('evt-L1-11'));
        self::assertSame($partition, $this->partitionRow($id));
        self::assertSame(['evt-L1-11'], $this->journal());

        $handler->script = [];
        self::assertSame(CustodyDisposition::Applied, $this->retry(self::integer($parked->inboxId), $handler)->disposition);
    }

    private function assertAuthDenied(Closure $action): void
    {
        try {
            $action();
            self::fail('Expected authorization denial');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::AuthDenied, $problem->problem);
        }
    }
}
