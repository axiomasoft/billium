<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
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
final class FeedRecoveryTest extends ObservationTestCase
{
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
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->attempt($restored));
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
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->attempt($regressed));
        self::assertSame(ProblemCode::RecoveryRequired->value, $this->attempt(ObservationFactory::charge('op-1', 3, 4000, sequence: 3)), 'the tail is held');

        self::assertSame('recovery_required', $this->partition()['state']);
        self::assertSame(2000, $this->allocated('o1'));
        self::assertSame(2, self::integer($this->observedRow()['revision']));
        self::assertSame(2, $this->rows('billium_allocation'));
    }
}
