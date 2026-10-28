<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Group;

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
