<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Drill;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use Billium\Platform\Data\Inbox\BootstrapStatus;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Tests\Inbox\InboxTestCase;
use Billium\Platform\Tests\Inbox\JournalHandler;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;

/**
 * Staged bootstrap of a recovering partition (PF-13, PF-14, ACC-RST-07, REQ-FAIL-22/23): a complete verified snapshot, a
 * durable disposition per item, crash-resume, and one short atomic adoption of the cursor. Never an active partial state.
 */
#[Group('PF-13')]
#[Group('PF-14')]
#[Group('ACC-RST-07')]
final class BootstrapAtomicAdoptionTest extends InboxTestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_staging_and_first_delivery_lock_the_same_namespace_before_looking_for_a_partition(bool $stageFirst): void
    {
        $store = $this->application()->make(PartitionStore::class);
        $competitor = new PostgresConnection(TestEnvironment::pdo());
        $competitor->statement("SET lock_timeout = '100ms'");
        $checked = false;
        $namespaceLockRequested = false;
        $this->application()->make(DatabaseManager::class)->connection()->beforeExecuting(function (string $sql) use ($store, $competitor, &$checked, &$namespaceLockRequested): void {
            if (str_contains($sql, 'pg_advisory_xact_lock')) {
                $namespaceLockRequested = true;
            }
            // First delivery may inspect optimistically, but admission must re-read under the namespace lock.
            if ($checked || ! $namespaceLockRequested || ! str_contains($sql, 'FROM public.billium_feed_partition')) {
                return;
            }
            $checked = true;
            $competitor->beginTransaction();
            try {
                $store->lockNamespace($competitor, 'payment.observation', self::AUTHORITY, 'seller-a', 'test', 'part-1');
                self::fail('another connection must not admit a partition or bootstrap while the namespace is being inspected');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $exception->getCode());
            } finally {
                $competitor->rollBack();
            }
            $competitor->transaction(function () use ($store, $competitor): void {
                $store->lockNamespace($competitor, 'payment.observation', self::AUTHORITY, 'seller-a', 'test', 'part-2');
            });
        });

        $handler = new JournalHandler;
        if ($stageFirst) {
            $session = $this->stage($this->snapshot(cursor: 20));
            $this->custody($this->envelope(1), $handler);
            self::assertSame(0, $this->rows('billium_feed_partition'), 'staging fences initial live creation');
            self::assertSame(0, $handler->calls);
        } else {
            $this->custody($this->envelope(1), $handler);
            $session = $this->stage($this->snapshot(cursor: 20));
            self::assertSame(self::integer($this->value('SELECT id FROM billium_feed_partition')), $this->bootstrapState($session)->oldPartitionId);
            self::assertSame(1, $handler->calls);
            self::assertSame(1, $this->rows('billium_feed_partition', "state = 'recovery_required'"));
        }
        self::assertTrue($checked);
        while ($this->applyNext($session, $handler) > 0) {
            // One item per recovery root.
        }
        $adopted = $this->root(fn (TransactionContext $context): int => $this->feed()->adoptBootstrap($context, $session));
        self::assertSame('adopted', $this->bootstrapState($session)->status);
        self::assertSame($adopted, $this->bootstrapState($session)->newPartitionId);
        self::assertSame(1, $this->rows('billium_feed_partition', "state = 'active'"));
    }

    public function test_a_recovering_partition_adopts_a_verified_bootstrap_only_after_every_item_is_disposed(): void
    {
        $old = $this->seedPartition(10);
        $handler = new JournalHandler(['op-3#1' => HandlerOutcome::parkedDependency('allocation', 'pay-9')]);
        // Source restored under a new lineage: deliveries are held, nothing is applied (PF-14).
        $hold = $this->custody($this->envelope(21, lineage: 'L2'), $handler);
        self::assertSame(CustodyDisposition::RecoveryRequired, $hold->disposition);
        $this->custody($this->envelope(22, lineage: 'L2'), $handler);
        $this->custody($this->envelope(19, lineage: 'L2'), $handler); // covered by the snapshot
        $this->custody($this->envelope(25, lineage: 'L1'), $handler); // old lineage tail
        self::assertSame(0, $handler->calls);

        $snapshot = $this->snapshot(cursor: 20);
        $session = $this->stage($snapshot);
        self::assertSame('verified', $this->bootstrapState($session)->status);
        self::assertSame('recovery_required', $this->partitionRow($old)['state'], 'the tail is held while staging');
        self::assertSame(3, $this->bootstrapState($session)->items);

        // Items are applied one per root; adoption before the last is refused and adopts nothing.
        self::assertSame(2, $this->applyNext($session, $handler));
        self::assertSame('applying', $this->bootstrapState($session)->status);
        $this->assertAdoptionRefused($session);

        // A crash after two items: a fresh process resumes from the item receipts and applies only the rest.
        self::assertSame(1, $this->applyNext($session, new JournalHandler(['op-3#1' => HandlerOutcome::parkedDependency('allocation', 'pay-9')])));
        self::assertSame(0, $this->applyNext($session, $handler));
        self::assertSame(['op-1#1', 'op-2#1', 'op-3#1'], $this->journal('snapshot'));
        $status = $this->bootstrapState($session);
        self::assertSame(['ready', 2, 1, 0, 0], [$status->status, $status->applied, $status->parked, $status->discrepancy, $status->staged]);
        $parked = $this->row("SELECT state, result_ref FROM billium_feed_bootstrap_item WHERE operation_ref = 'op-3'");
        self::assertSame(['parked', 'allocation:pay-9'], [$parked['state'], $parked['result_ref']]);
        self::assertSame('recovery_required', $this->partitionRow($old)['state'], 'ready is not adopted');

        // The adoption Tx fails after the switch: everything rolls back, the partition is still not active-partial.
        try {
            $this->root(function (TransactionContext $context) use ($session): void {
                $this->feed()->adoptBootstrap($context, $session);

                throw new RuntimeException('crash inside adoption');
            });
            self::fail('The injected failure must propagate');
        } catch (RuntimeException) {
            // expected
        }
        self::assertSame('ready', $this->bootstrapState($session)->status);
        self::assertSame('recovery_required', $this->partitionRow($old)['state']);
        self::assertSame(1, $this->rows('billium_feed_partition'));

        $adopted = $this->root(fn (TransactionContext $context): int => $this->feed()->adoptBootstrap($context, $session));
        self::assertIsInt($adopted);
        $new = $this->partitionRow($adopted);
        self::assertSame(['active', 'L2', 2, 20, 22], [$new['state'], $new['source_lineage'], $new['generation'], $new['accounted_sequence'], $new['custody_sequence']]);
        self::assertSame('retired', $this->partitionRow($old)['state']);
        self::assertSame(['adopted', $old, $adopted], [$this->bootstrapState($session)->status, $this->bootstrapState($session)->oldPartitionId, $this->bootstrapState($session)->newPartitionId]);
        // Idempotent: adopting again answers with the same partition.
        self::assertSame($adopted, $this->root(fn (TransactionContext $context): int => $this->feed()->adoptBootstrap($context, $session)));

        // The held tail above the cursor drains one sequence per root; what the snapshot covers is superseded, the old lineage stays apart.
        self::assertSame(['ready', $adopted], [$this->inbox('evt-L2-21')['status'], $this->inbox('evt-L2-21')['partition_id']]);
        self::assertSame(['parked_gap', 'quarantined', 'held_recovery'], [$this->inbox('evt-L2-22')['status'], $this->inbox('evt-L2-19')['status'], $this->inbox('evt-L1-25')['status']]);
        self::assertSame('superseded_by_bootstrap', $this->inbox('evt-L2-19')['last_error_class']);
        self::assertSame($old, $this->inbox('evt-L1-25')['partition_id']);

        $live = new JournalHandler;
        self::assertCount(2, $this->drainAll($adopted, $live));
        self::assertSame(['evt-L2-21', 'evt-L2-22'], $this->journal());
        self::assertSame(22, $this->partitionRow($adopted)['accounted_sequence']);
        // The bootstrapped lineage continues live from the cursor.
        self::assertSame(CustodyDisposition::Applied, $this->custody($this->envelope(23, lineage: 'L2'), $live)->disposition);
    }

    public function test_an_incomplete_or_altered_snapshot_stages_nothing(): void
    {
        $old = $this->seedPartition(10);
        $good = $this->snapshot(cursor: 20);
        $items = $good->items;

        $incomplete = new BootstrapPartition('bs-1', 'payment.observation', self::AUTHORITY, 'L2', 'seller-a', 'test', 'part-1', 20,
            BootstrapPartition::digestOf(20, $items, $good->namespace()), 'lease-1', 4, $items);
        $altered = new BootstrapPartition('bs-2', 'payment.observation', self::AUTHORITY, 'L2', 'seller-a', 'test', 'part-1', 20,
            $good->snapshotDigest, 'lease-1', 3, [...array_slice($items, 0, 2), new BootstrapItem('op-3', 1, 'sha256:'.str_repeat('f', 64), new stdClass)]);
        $foreign = new BootstrapPartition('bs-3', 'payment.observation', 'remote-billium', 'L2', 'seller-a', 'test', 'part-1', 20,
            $good->snapshotDigest, 'lease-1', 3, $items);
        foreach ([[$incomplete, ProblemCode::ValidationInvalid], [$altered, ProblemCode::ValidationInvalid], [$foreign, ProblemCode::AuthDenied]] as [$snapshot, $code]) {
            try {
                $this->stage($snapshot);
                self::fail('The snapshot must be refused');
            } catch (ProblemException $refusal) {
                self::assertSame($code, $refusal->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_feed_bootstrap_session'));
        self::assertSame(0, $this->rows('billium_feed_bootstrap_item'));
        self::assertSame('active', $this->partitionRow($old)['state'], 'a refused snapshot does not stop the live partition');

        // The same reference with another digest is a conflict, with the same digest it resumes the session.
        $session = $this->stage($good);
        self::assertSame($session, $this->stage($good));
        $other = new BootstrapPartition($good->bootstrapRef, 'payment.observation', self::AUTHORITY, 'L2', 'seller-a', 'test', 'part-1', 21,
            BootstrapPartition::digestOf(21, $items, $good->namespace()), 'lease-1', 3, $items);
        $this->expectException(ProblemException::class);
        $this->stage($other);
    }

    public function test_the_database_refuses_an_adoption_that_skips_item_dispositions(): void
    {
        $this->seedPartition(10);
        $session = $this->stage($this->snapshot(cursor: 20));
        $owner = TestEnvironment::pdo(owner: true);
        $this->applyNext($session, new JournalHandler);

        // Even a privileged writer cannot mark a bootstrap ready or adopted around its undisposed items.
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_feed_bootstrap_session SET status = 'ready' WHERE id = {$session}"));
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_feed_bootstrap_session SET status = 'adopted', new_partition_id = old_partition_id WHERE id = {$session}"));
        // Snapshot content and a durable disposition are immutable; nothing is deleted.
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_feed_bootstrap_item SET material_digest = 'sha256:".str_repeat('0', 64)."' WHERE session_id = {$session}"));
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_feed_bootstrap_item SET state = 'discrepancy' WHERE session_id = {$session} AND state = 'applied'"));
        self::assertSame('55000', self::sqlState($owner, 'DELETE FROM billium_feed_bootstrap_item'));
    }

    public function test_the_database_rejects_cross_namespace_items_and_verification_of_an_incomplete_manifest(): void
    {
        $this->seedPartition(10);
        $session = $this->stage($this->snapshot(cursor: 20));
        $owner = TestEnvironment::pdo(owner: true);
        $loading = self::integer($this->value(<<<'SQL'
            INSERT INTO billium_feed_bootstrap_session(bootstrap_ref,channel,source_authority,source_lineage,seller_ref,environment,
                partition_ref,cursor_sequence,snapshot_digest,item_count,source_retention_lease_ref)
            SELECT 'guarded-manifest',channel,source_authority,source_lineage,seller_ref,environment,'part-2',cursor_sequence,
                snapshot_digest,item_count,source_retention_lease_ref FROM billium_feed_bootstrap_session WHERE id=? RETURNING id
            SQL, [$session]));
        self::assertSame('23514', self::sqlState($owner, "INSERT INTO billium_feed_bootstrap_item(session_id,operation_ref,revision,material_digest,payload)
            SELECT {$loading},operation_ref,revision,material_digest,payload FROM billium_feed_bootstrap_item WHERE session_id={$session} LIMIT 1"));
        self::assertSame('23514', self::sqlState($owner, "UPDATE billium_feed_bootstrap_session SET status='verified' WHERE id={$loading}"));
        self::assertSame('loading', $this->value('SELECT status FROM billium_feed_bootstrap_session WHERE id=?', [$loading]));
        self::assertSame(0, $this->rows('billium_feed_bootstrap_item', 'session_id='.$loading));
    }

    private function snapshot(int $cursor, string $ref = 'bs-1'): BootstrapPartition
    {
        $items = [];
        foreach (['op-1', 'op-2', 'op-3'] as $operation) {
            $items[] = new BootstrapItem($operation, 1, 'sha256:'.hash('sha256', $operation), (object) [
                'installationRef' => self::INSTALLATION, 'sourceAuthority' => self::AUTHORITY, 'sourceLineage' => 'L2',
                'sellerRef' => 'seller-a', 'environment' => 'test', 'partitionRef' => 'part-1',
                'operationRef' => $operation, 'operationRevision' => 1, 'capturedTotal' => '10.00',
            ]);
        }

        return new BootstrapPartition($ref, 'payment.observation', self::AUTHORITY, 'L2', 'seller-a', 'test', 'part-1', $cursor,
            BootstrapPartition::digestOf($cursor, $items, ['channel' => 'payment.observation', 'sourceAuthority' => self::AUTHORITY,
                'sourceLineage' => 'L2', 'sellerRef' => 'seller-a', 'environment' => 'test', 'partitionRef' => 'part-1']), 'lease-1', count($items), $items);
    }

    private function stage(BootstrapPartition $snapshot): int
    {
        $session = $this->root(fn (TransactionContext $context): int => $this->feed()->stageBootstrap($context, $snapshot));

        return is_int($session) ? $session : self::fail('stage returned no session');
    }

    private function applyNext(int $session, JournalHandler $handler): int
    {
        $remaining = $this->root(fn (TransactionContext $context): int => $this->feed()->applyBootstrapItem($context, $session, $handler));

        return is_int($remaining) ? $remaining : self::fail('apply returned no count');
    }

    private function bootstrapState(int $session): BootstrapStatus
    {
        $status = $this->root(fn (TransactionContext $context) => $this->feed()->bootstrapStatus($context, $session));

        return $status instanceof BootstrapStatus ? $status : self::fail('no status');
    }

    private function assertAdoptionRefused(int $session): void
    {
        try {
            $this->root(fn (TransactionContext $context): int => $this->feed()->adoptBootstrap($context, $session));
            self::fail('Adoption with undisposed items must be refused');
        } catch (ProblemException $refusal) {
            self::assertSame(ProblemCode::RecoveryRequired, $refusal->problem);
        }
        self::assertSame(1, $this->rows('billium_feed_partition'));
        self::assertSame(0, $this->rows('billium_feed_partition', "state = 'active'"));
    }
}
