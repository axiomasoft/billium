<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
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
