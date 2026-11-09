<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Billium\Platform\Contracts\FeedPartitions;
use Billium\Platform\Data\Inbox\BootstrapPartition;
use PHPUnit\Framework\Attributes\Group;

/**
 * PF-13 (REQ-CON-27, D60 staged bootstrap): a snapshot of three operations at cursor 20 is verified, every item gets a
 * durable disposition (an item without its settlement intent is an explicit pending allocation, not lost capture
 * evidence), the cursor is adopted in one short root and sequences 21..23 follow, including one that arrived while the
 * partition did not exist yet. An altered snapshot is refused before any item is applied.
 */
#[Group('PF-13')]
final class FeedBootstrapTest extends ObservationTestCase
{
    public function test_bootstrap_session_access_requires_its_source_authority_and_denial_preserves_items(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $authorized = new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['remote-billium']);
        $this->callers->set($authorized);
        $snapshot = $this->snapshot('remote-bootstrap', 'L1', 20, [
            ObservationFactory::charge('op-1', 1, 1000, fields: ['sourceAuthority' => 'remote-billium']),
        ], authority: 'remote-billium');
        $feed = $this->application()->make(FeedPartitions::class);
        $handler = $this->application()->make(ObservationHandler::class);
        $session = $this->drainer()->root(fn (TransactionContext $context): int => $feed->stageBootstrap($context, $snapshot));
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['embedded-commerz']));

        foreach ([
            fn (TransactionContext $context) => $feed->applyBootstrapItem($context, $session, $handler),
            fn (TransactionContext $context) => $feed->adoptBootstrap($context, $session),
            fn (TransactionContext $context) => $feed->bootstrapStatus($context, $session),
        ] as $operation) {
            try {
                $this->drainer()->root($operation);
                self::fail('A bootstrap session requires its source authority');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::AuthDenied, $error->problem);
            }
            self::assertSame('staged', $this->value('SELECT state FROM billium_feed_bootstrap_item WHERE session_id=?', [$session]));
            self::assertNull($this->value('SELECT disposed_at FROM billium_feed_bootstrap_item WHERE session_id=?', [$session]));
            self::assertSame('verified', $this->value('SELECT status FROM billium_feed_bootstrap_session WHERE id=?', [$session]));
            self::assertNull($this->value('SELECT new_partition_id FROM billium_feed_bootstrap_session WHERE id=?', [$session]));
            self::assertSame(0, $this->rows('billium_feed_partition'));
            self::assertSame(0, $this->rows('billium_observed_payment'));
            self::assertSame(0, $this->rows('billium_payment'));
            self::assertSame(0, $this->rows('billium_allocation'));
        }

        $this->callers->set($authorized);
        self::assertSame(0, $this->drainer()->root(fn (TransactionContext $context): int => $feed->applyBootstrapItem($context, $session, $handler)));
        $partition = $this->drainer()->root(fn (TransactionContext $context): int => $feed->adoptBootstrap($context, $session));
        $status = $this->drainer()->root(fn (TransactionContext $context) => $feed->bootstrapStatus($context, $session));
        self::assertSame(['adopted', 1, 0, 0, $partition], [$status->status, $status->applied, $status->staged, $status->discrepancy, $status->newPartitionId]);
        self::assertSame(['active', 'remote-billium', 20], [$this->partition()['state'], $this->partition()['source_authority'], self::integer($this->partition()['accounted_sequence'])]);
        self::assertSame(1000, self::integer($this->observedRow()['captured_minor']));
        self::assertSame(1000, $this->allocated('o1'));
        self::assertSame(1, $this->rows('billium_payment'));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
    }

    public function test_manifest_namespace_and_item_namespace_are_verified_before_staging_or_resuming(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $valid = $this->snapshot('namespace', 'L1', 20, [ObservationFactory::charge('op-1', 1, 1000)]);
        foreach (['partitionRef' => 'part-2', 'sourceAuthority' => 'remote-billium', 'sourceLineage' => 'L2', 'channel' => 'another.channel'] as $field => $value) {
            $changed = self::withManifest($valid, $field, $value);
            try {
                $this->bootstrapper()->execute($changed);
                self::fail('A manifest change cannot retain the original snapshot digest: '.$field);
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            }
        }
        // Recomputing the digest cannot bless a payload belonging to another authorized source or lineage.
        foreach (['partitionRef' => 'part-2', 'sourceAuthority' => 'remote-billium', 'sourceLineage' => 'L2'] as $field => $value) {
            $changed = self::withManifest($valid, $field, $value);
            $changed = self::withManifest($changed, 'snapshotDigest', BootstrapPartition::digestOf($changed->cursorSequence, $changed->items, $changed->namespace()));
            try {
                $this->bootstrapper()->execute($changed);
                self::fail('Every item must match its manifest: '.$field);
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_feed_bootstrap_session'));
        self::assertSame(0, $this->rows('billium_feed_bootstrap_item'));
        self::assertSame(0, $this->rows('billium_observed_payment'));
        self::assertSame(0, $this->rows('billium_feed_partition'));

        $feed = $this->application()->make(FeedPartitions::class);
        $session = $this->drainer()->root(fn (TransactionContext $context): int => $feed->stageBootstrap($context, $valid));
        foreach (['partitionRef' => 'part-2', 'sourceAuthority' => 'remote-billium', 'sourceLineage' => 'L2', 'channel' => 'another.channel',
            'sourceRetentionLeaseRef' => 'another-lease'] as $field => $value) {
            $changed = self::withManifest($valid, $field, $value);
            try {
                $this->drainer()->root(fn (TransactionContext $context): int => $feed->stageBootstrap($context, $changed));
                self::fail('Resume must retain the complete manifest identity: '.$field);
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ObservationConflict, $error->problem);
            }
        }
        self::assertSame('verified', $this->value('SELECT status FROM billium_feed_bootstrap_session WHERE id=?', [$session]));
        self::assertSame(0, $this->rows('billium_observed_payment'));
        self::assertSame(0, $this->rows('billium_feed_partition'));
        $adopted = $this->bootstrapper()->execute($valid);
        self::assertSame('adopted', $adopted->status);
        self::assertSame(20, self::integer($this->partition()['accounted_sequence']));
        self::assertSame(1000, $this->allocated('o1'));
    }

    private static function withManifest(BootstrapPartition $snapshot, string $field, string $value): BootstrapPartition
    {
        return new BootstrapPartition($snapshot->bootstrapRef, $field === 'channel' ? $value : $snapshot->channel,
            $field === 'sourceAuthority' ? $value : $snapshot->sourceAuthority, $field === 'sourceLineage' ? $value : $snapshot->sourceLineage,
            $snapshot->sellerRef, $snapshot->environment, $field === 'partitionRef' ? $value : $snapshot->partitionRef,
            $snapshot->cursorSequence, $field === 'snapshotDigest' ? $value : $snapshot->snapshotDigest,
            $field === 'sourceRetentionLeaseRef' ? $value : $snapshot->sourceRetentionLeaseRef, $snapshot->expectedItemCount, $snapshot->items);
    }

    public function test_a_snapshot_at_cursor_twenty_is_adopted_and_the_tail_follows(): void
    {
        $this->debt('o1', 10000);
        $this->debt('o3', 10000);
        $this->intent('intent-a', ['o1']);
        $early = ObservationFactory::charge('op-1', 2, 2000, sequence: 21);
        self::assertSame(ProblemCode::ObservationParked->value, $this->attempt($early), 'no partition yet: custody waits for the cursor');

        $status = $this->bootstrapper()->execute($this->snapshot('boot-1', 'L1', 20, [
            ObservationFactory::charge('op-1', 1, 1000, sequence: 7),
            ObservationFactory::charge('op-2', 1, 500, sequence: 12),
            ObservationFactory::charge('op-3', 1, 300, sequence: 20, fields: ['businessIntentRef' => 'intent-c']),
        ]));

        self::assertSame(['adopted', 3, 2, 1, 0], [$status->status, $status->items, $status->applied, $status->parked, $status->staged]);
        self::assertSame(300, self::integer($this->observedRow('op-3')['captured_minor']), 'the pending item keeps its capture evidence');
        self::assertSame('applied', $this->inboxStatus(self::eventId($early)), 'the held tail drained after adoption');
        self::assertSame(2500, $this->allocated('o1'), 'op-1 1000 → 2000 and op-2 500 on the same intent');

        $this->observe(ObservationFactory::charge('op-2', 2, 700, sequence: 22));
        self::assertSame(ProblemCode::ObservationParked->value, $this->attempt(ObservationFactory::charge('op-3', 2, 600, sequence: 23, fields: ['businessIntentRef' => 'intent-c'])));
        self::assertSame(['active', 23, 'L1'], [$this->partition()['state'], self::integer($this->partition()['accounted_sequence']), $this->partition()['source_lineage']]);
        self::assertSame(2700, $this->allocated('o1'));

        $this->intent('intent-c', ['o3']);
        self::assertSame(['applied', 'applied'], $this->drainer()->retryParked());
        self::assertSame(600, $this->allocated('o3'));
        self::assertSame(6, $this->rows('billium_allocation'));
        self::assertSame(0, $this->rows('billium_observation_park', 'released_at IS NULL'));
    }

    public function test_an_altered_snapshot_is_refused_before_any_item(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $valid = $this->snapshot('boot-x', 'L1', 20, [ObservationFactory::charge('op-1', 1, 1000)]);
        $altered = new BootstrapPartition($valid->bootstrapRef, $valid->channel, $valid->sourceAuthority, $valid->sourceLineage, $valid->sellerRef,
            $valid->environment, $valid->partitionRef, 21, $valid->snapshotDigest, $valid->sourceRetentionLeaseRef, 1, $valid->items);

        try {
            $this->bootstrapper()->execute($altered);
            self::fail('an altered snapshot must not verify');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::ValidationInvalid, $problem->problem);
        }
        self::assertSame(0, $this->rows('billium_feed_bootstrap_session'));
        self::assertSame(0, $this->rows('billium_observed_payment'));
        self::assertSame(0, $this->rows('billium_allocation'));
    }
}
