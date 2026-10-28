<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Data\Feed\FeedSelection;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Internal\Outbox\OutboxPruner;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Testing\PendingCommand;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('ACC-RST-06')]
final class FeedPruneBootstrapTest extends OutboxTestCase
{
    public function test_pruned_old_cursor_requires_bootstrap_and_retains_the_complete_tail(): void
    {
        $selection = $this->selection();
        $this->source();
        $this->events(50);
        $this->publisher();
        self::assertSame(50, $this->relay()->relay(100)->delivered);
        self::assertSame(50, $this->pruner()->prune());
        try {
            $this->feed()->read($selection);
            self::fail('Missing history must fail explicitly');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::RecoveryRequired, $error->problem);
        }
        $bootstrap = $this->feed()->bootstrap($selection);
        self::assertCount(50, $bootstrap->snapshots['ledger.obligations']);
        $this->events(10);
        $this->feed()->acknowledgeBootstrap($selection, $bootstrap->leaseRef, $bootstrap->snapshotDigest);
        $tail = $this->feed()->read($selection, $bootstrap->cursor->token);
        self::assertCount(10, $tail->events);
        self::assertSame(60, count($bootstrap->snapshots['ledger.obligations']) + count($tail->events));
    }

    public function test_prune_works_in_bounded_batches_and_stops_at_the_first_blocked_row(): void
    {
        $this->events(20);
        $this->publisher();
        self::assertSame(20, $this->relay()->relay(100)->delivered);
        // Row 12 stays undeliverable: batches of 5 must still stop exactly before it.
        $blocked = self::text($this->value('SELECT event_id FROM billium_outbox_event ORDER BY tx_id,id OFFSET 11 LIMIT 1'));
        $this->owner()->update("UPDATE billium_outbox_delivery SET status = 'pending',delivered_at = NULL WHERE event_id = ?", [$blocked]);
        self::assertSame(11, new OutboxPruner($this->owner(), 0, 5)->prune());
        self::assertSame(9, $this->rows('billium_outbox_event'));
        self::assertSame(0, new OutboxPruner($this->owner(), 0, 5)->prune());
        $this->owner()->update("UPDATE billium_outbox_delivery SET status = 'delivered',delivered_at = clock_timestamp() WHERE event_id = ?", [$blocked]);
        self::assertSame(9, new OutboxPruner($this->owner(), 0, 5)->prune());
        self::assertSame(0, $this->rows('billium_outbox_event'));
    }

    public function test_prune_refuses_a_nonpositive_batch(): void
    {
        $this->expectException(LogicException::class);
        new OutboxPruner($this->owner(), 0, 0)->prune();
    }

    public function test_closing_a_required_feed_releases_the_prune_pin_and_cannot_be_undone(): void
    {
        $selection = $this->selection(required: true);
        $this->events(2);
        $this->publisher();
        $this->relay()->relay();
        self::assertSame(0, $this->pruner()->prune());
        $this->feed()->closeFeed($selection);
        self::assertSame('23514', self::sqlState(TestEnvironment::pdo(), 'UPDATE billium_feed_subscription SET closed_at = NULL'));
        self::assertSame(2, $this->pruner()->prune());
        try {
            $this->feed()->read($selection);
            self::fail('A closed feed is gone');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
    }

    public function test_open_required_feeds_are_bounded_per_scope(): void
    {
        for ($i = 0; $i < EventFeedReader::MAX_OPEN_REQUIRED; $i++) {
            $last = $this->selection(required: true);
        }
        try {
            $this->selection(required: true);
            self::fail('Required subscriptions are bounded');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        self::assertInstanceOf(FeedSelection::class, $this->selection());
        $this->feed()->closeFeed($last);
        self::assertInstanceOf(FeedSelection::class, $this->selection(required: true));
    }

    public function test_failed_snapshot_releases_its_pin(): void
    {
        $selection = $this->selection();
        $source = $this->source();
        $this->events(2);
        $this->publisher();
        $this->relay()->relay();
        $source->duringSnapshot = static fn () => throw new RuntimeException('snapshot failed');
        try {
            $this->feed()->bootstrap($selection);
            self::fail('The source failure must surface');
        } catch (RuntimeException $error) {
            self::assertSame('snapshot failed', $error->getMessage());
        }
        self::assertSame('expired', $this->value('SELECT state FROM billium_feed_bootstrap_lease'));
        self::assertSame(2, $this->pruner()->prune(), 'a failed snapshot must not block prune until the lease runs out');
    }

    public function test_no_retention_floor_refuses_and_undelivered_work_blocks_the_prefix(): void
    {
        $command = $this->artisan('billium:outbox:prune');
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(1);
        $this->events(2);
        $this->owner()->update("UPDATE billium_outbox_delivery SET status = 'delivered',delivered_at = clock_timestamp() WHERE id = (SELECT max(id) FROM billium_outbox_delivery)");
        self::assertSame(0, $this->pruner()->prune());
        self::assertSame(2, $this->rows('billium_outbox_event'));
    }

    public function test_required_consumer_floor_and_active_bootstrap_pin_are_respected(): void
    {
        $selection = $this->selection(required: true);
        $this->source();
        $this->events(2);
        $this->publisher();
        $this->relay()->relay();
        self::assertSame(0, $this->pruner()->prune());
        $bootstrap = $this->feed()->bootstrap($selection);
        $page = $this->feed()->read($selection);
        $this->feed()->acknowledgeCursor($selection, $page->nextCursor->token);
        self::assertSame('23514', self::sqlState(TestEnvironment::pdo(), "UPDATE billium_feed_subscription SET floor_tx_id = '0',floor_id = 0"));
        self::assertSame(0, $this->pruner()->prune(), 'bootstrap pin remains through staged transfer');
        $this->feed()->acknowledgeBootstrap($selection, $bootstrap->leaseRef, $bootstrap->snapshotDigest);
        self::assertSame(2, $this->pruner()->prune());
    }
}
