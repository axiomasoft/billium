<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Billium\Platform\Tests\Outbox\TestSnapshotSource;
use PHPUnit\Framework\Attributes\Group;

#[Group('EVT-05')]
#[Group('pg')]
#[Group('pg-serial')]
final class FilteredFeedBootstrapTest extends OutboxTestCase
{
    public function test_only_selected_snapshot_sources_compose_and_replay_only_below_floor_refuses(): void
    {
        $selected = $this->source();
        $unselected = $this->source('ledger.invoices');
        $selection = $this->selection();
        $this->events(2);
        $this->publisher();
        $this->relay()->relay();
        $bootstrap = $this->feed()->bootstrap($selection);
        self::assertSame(1, $selected->calls);
        self::assertSame(0, $unselected->calls);
        self::assertSame(['ledger.obligations'], array_keys($bootstrap->snapshots));
        $this->feed()->acknowledgeBootstrap($selection, $bootstrap->leaseRef, $bootstrap->snapshotDigest);
        self::assertSame(2, $this->pruner()->prune());
        $this->registerExecution();
        $replay = $this->feed()->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v2', ['billium.event.v2'], ['payment.effect.outcome.v1']);
        $this->expectException(ProblemException::class);
        $this->feed()->bootstrap($replay);
    }

    public function test_missing_selected_historical_source_fails_closed(): void
    {
        // Production now registers this stream. Model its historical key being unavailable explicitly.
        $this->application()->instance('billium.snapshot.ledger.obligations', new TestSnapshotSource('ledger.unavailable'));
        try {
            $this->feed()->bootstrap($this->selection());
            self::fail('Cannot synthesize an empty ledger snapshot');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::RecoveryRequired, $error->problem);
        }
    }
}
