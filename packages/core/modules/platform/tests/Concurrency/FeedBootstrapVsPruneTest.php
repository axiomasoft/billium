<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('EVT-05')]
#[Group('pg')]
#[Group('pg-serial')]
final class FeedBootstrapVsPruneTest extends OutboxTestCase
{
    public function test_snapshot_overlap_with_a_horizon_delayed_tail_preserves_economic_identity(): void
    {
        $selection = $this->selection();
        $this->source();
        $blocker = TestEnvironment::pdo(owner: true);
        $blocker->beginTransaction();
        try {
            $blocker->query('SELECT pg_current_xact_id()');
            $this->events(1);
            $bootstrap = $this->feed()->bootstrap($selection);
            self::assertSame('0', $bootstrap->cursor->position->txId);
            self::assertCount(1, $bootstrap->snapshots['ledger.obligations']);
            $blocker->commit();
        } finally {
            if ($blocker->inTransaction()) {
                $blocker->rollBack();
            }
        }
        $tail = $this->feed()->read($selection, $bootstrap->cursor->token);
        self::assertCount(1, $tail->events);
        $snapshotItem = $bootstrap->snapshots['ledger.obligations'][0];
        self::assertSame($snapshotItem['operationRef'], $tail->events[0]['aggregateRef']);
        self::assertSame($snapshotItem['revision'], $tail->events[0]['revision']);
        self::assertSame($snapshotItem['materialDigest'], $tail->events[0]['digest']);
        $dispositions = [];
        $dispositions[self::text($snapshotItem['operationRef']).':'.self::integer($snapshotItem['revision'])] = true;
        foreach ($tail->events as $event) {
            $dispositions[self::text($event['aggregateRef']).':'.self::integer($event['revision'])] = true;
        }
        self::assertCount(1, $dispositions, 'snapshot and tail identify the same economic revision');
    }

    public function test_prune_during_snapshot_transfer_observes_the_reserved_pin_and_expiry_requires_rebootstrap(): void
    {
        $selection = $this->selection();
        $source = $this->source();
        $this->events(3);
        $this->publisher();
        $this->relay()->relay();
        $source->duringSnapshot = function (): void {
            // Independent owner Tx runs while source connection holds its REPEATABLE READ snapshot.
            self::assertSame(1, $this->rows('billium_feed_bootstrap_lease', "state = 'active'"));
            self::assertSame(0, $this->pruner()->prune());
        };
        $bootstrap = $this->feed()->bootstrap($selection);
        self::assertCount(3, $bootstrap->snapshots['ledger.obligations']);
        $this->owner()->update("UPDATE billium_feed_bootstrap_lease SET expires_at = clock_timestamp() - interval '1 second'");
        self::assertSame(3, $this->pruner()->prune());
        try {
            $this->feed()->read($selection, $bootstrap->cursor->token);
            self::fail('Expired transfer cannot return a successful incomplete tail');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::RecoveryRequired, $error->problem);
        }
    }
}
