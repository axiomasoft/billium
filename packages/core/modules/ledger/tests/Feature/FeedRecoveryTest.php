<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * PF-14, ACC-RST-07 (REQ-FAIL-23, D60): a restored source with another lineage, or a new event at an already accounted
 * sequence, puts the partition into recovery_required with zero economic effect; ledger.feed.bootstrap with the new
 * lineage and its cursor activates the partition, the held tail applies after the cursor and no total is doubled.
 */
#[Group('PF-14')]
#[Group('ACC-RST-07')]
#[Group('pg')]
final class FeedRecoveryTest extends ObservationTestCase
{
    public function test_a_retired_namespace_holds_sequence_one_until_another_bootstrap_is_adopted(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 300));
        // A ready recovery that is retired then abandoned models the legacy no-live-partition state.
        $this->owner()->unprepared(<<<'SQL'
            INSERT INTO billium_feed_bootstrap_session(bootstrap_ref,channel,source_authority,source_lineage,seller_ref,environment,
                partition_ref,cursor_sequence,snapshot_digest,item_count,source_retention_lease_ref,status,old_partition_id)
            SELECT 'abandoned','payment.observation','embedded-commerz','L2','seller-a','test','part-1',1,
                'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',0,'lease-abandoned','ready',id FROM billium_feed_partition;
            UPDATE billium_feed_partition SET state='retired';
            SQL);
        // Ready evidence permits retirement, but the replacement cannot commit without adoption in the same Tx.
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(), <<<'SQL'
            INSERT INTO billium_feed_partition(channel,source_authority,source_lineage,seller_ref,environment,partition_ref,generation,state,custody_sequence,accounted_sequence)
            VALUES('payment.observation','embedded-commerz','L2','seller-a','test','part-1',2,'active',1,1)
            SQL));
        $this->owner()->update("UPDATE billium_feed_bootstrap_session SET status='failed'");
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(), <<<'SQL'
            INSERT INTO billium_feed_partition(channel,source_authority,source_lineage,seller_ref,environment,partition_ref,generation,state)
            VALUES('payment.observation','embedded-commerz','L2','seller-a','test','part-1',2,'active')
            SQL));
        $restored = ObservationFactory::charge('op-1', 2, 600, fields: ['sourceLineage' => 'L2']);
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->tryObserve($restored));
        self::assertSame(1, $this->rows('billium_feed_partition'));
        self::assertSame('held_recovery', $this->inboxStatus(self::eventId($restored)));
        self::assertSame(300, $this->allocated('o1'));
        self::assertSame('adopted', $this->bootstrapper()->execute($this->snapshot('boot-retired', 'L2', 1, [$restored]))->status);
        self::assertSame(600, $this->allocated('o1'));
        self::assertSame(2, self::integer($this->partition()['generation']));
    }

    public function test_recovery_cannot_be_cleared_by_retiring_and_reopening_at_sequence_one(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $charge = ObservationFactory::charge('op-1', 1, 300);
        $this->observe($charge);
        $restored = ObservationFactory::charge('op-1', 2, 600, fields: ['sourceLineage' => 'L2']);
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->tryObserve($restored));
        $runtime = TestEnvironment::pdo();
        self::assertSame('55000', self::sqlState($runtime, "UPDATE billium_feed_partition SET state='retired'"));
        self::assertSame('recovery_required', $this->partition()['state']);
        self::assertSame('23505', self::sqlState($runtime, <<<'SQL'
            INSERT INTO billium_feed_partition(channel,source_authority,source_lineage,seller_ref,environment,partition_ref,generation,state)
            VALUES('payment.observation','embedded-commerz','L2','seller-a','test','part-1',2,'active')
            SQL));
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->tryObserve(ObservationFactory::with($restored, ['eventId' => (string) Str::ulid()])));
        self::assertSame(300, $this->allocated('o1'));
        self::assertSame(1, $this->rows('billium_feed_partition'));
        $status = $this->bootstrapper()->execute($this->snapshot('boot-recovery', 'L2', 1, [$restored]));
        self::assertSame('adopted', $status->status);
        self::assertSame(600, $this->allocated('o1'));
        self::assertSame('active', $this->partition()['state']);
    }

    public function test_a_changed_lineage_requires_recovery_and_bootstrap_resumes_without_doubling(): void
    {
        $this->debt('o1', 5000);
        $this->debt('o2', 500);
        $this->intent('intent-a', ['o1']);
        $this->intent('intent-b', ['o2']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $applied = ObservationFactory::charge('op-2', 1, 500, sequence: 2, fields: ['businessIntentRef' => 'intent-b']);
        $this->observe($applied);

        $restored = ObservationFactory::charge('op-1', 2, 2500, sequence: 3, fields: ['sourceLineage' => 'L2']);
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->tryObserve($restored));
        self::assertSame('recovery_required', $this->partition()['state']);
        self::assertSame(1000, $this->allocated('o1'), '0 effect while recovery is required');
        self::assertSame(1, self::integer($this->observedRow()['revision']));

        $status = $this->bootstrapper()->execute($this->snapshot('boot-l2', 'L2', 3, [
            ObservationFactory::with($restored, ['sourceSequence' => 3]),
            ObservationFactory::with($applied, ['sourceLineage' => 'L2']),
        ]));

        self::assertSame(['adopted', 2, 0, 0, 0], [$status->status, $status->applied, $status->parked, $status->discrepancy, $status->staged]);
        $partition = $this->partition();
        self::assertSame(['active', 'L2', 3, 2], [$partition['state'], $partition['source_lineage'], self::integer($partition['accounted_sequence']), self::integer($partition['generation'])]);
        self::assertSame('quarantined', $this->inboxStatus(self::eventId($restored)), 'the held delivery is covered by the snapshot, never applied twice');
        self::assertSame(2500, $this->allocated('o1'));
        self::assertSame(500, $this->allocated('o2'), 'the already applied operation replays');

        $tail = $this->observe(ObservationFactory::charge('op-1', 3, 3000, sequence: 4, fields: ['sourceLineage' => 'L2']));
        self::assertSame('assign', $tail->mode());
        self::assertSame(3000, $this->allocated('o1'));
        self::assertSame(3500, $this->journalSum());
        self::assertSame(4, $this->rows('billium_allocation'));
        self::assertSame(3000, self::integer($this->paymentRow()['captured_minor']));
        self::assertSame(4, self::integer($this->partition()['accounted_sequence']));
    }

    public function test_a_new_event_at_an_accounted_sequence_requires_recovery_with_no_effect(): void
    {
        $this->debt('o1', 5000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $this->observe(ObservationFactory::charge('op-1', 2, 2000, sequence: 2));

        $regressed = ObservationFactory::charge('op-1', 3, 4000, sequence: 2, fields: ['eventId' => (string) Str::ulid()]);
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->tryObserve($regressed));
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->tryObserve(ObservationFactory::charge('op-1', 3, 4000, sequence: 3)), 'the tail is held');

        self::assertSame('recovery_required', $this->partition()['state']);
        self::assertSame(2000, $this->allocated('o1'));
        self::assertSame(2, self::integer($this->observedRow()['revision']));
        self::assertSame(2, $this->rows('billium_allocation'));
    }
}
