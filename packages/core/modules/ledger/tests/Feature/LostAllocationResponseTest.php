<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Ledger\Tests\Queries\QueryTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('ACC-RST-05')]
#[Group('pg')]
final class LostAllocationResponseTest extends QueryTestCase
{
    public function test_lost_allocation_reply_replays_the_original_receipt_and_can_be_queried(): void
    {
        $this->seedQueryState();
        // The first allocation response was discarded; recovery uses the same command key and immutable receipt.
        $stored = $this->queries()->receipt('allocation:allocation-a');
        $counts = [$this->rows('billium_allocation'), $this->rows('billium_allocation_receipt'), $this->rows('billium_outbox_event')];
        $replayed = $this->allocateFrom('allocation-a', 'obligation-a', '50.00', 'op-1')->orThrow();
        self::assertTrue($replayed->replayed);
        self::assertSame($stored['receipt_ref'], $replayed->body['receipt_ref']);
        self::assertEquals($stored, $replayed->body);
        self::assertEquals($stored, $this->queries()->receipt(self::text($replayed->body['receipt_ref'])));
        self::assertSame($counts, [$this->rows('billium_allocation'), $this->rows('billium_allocation_receipt'), $this->rows('billium_outbox_event')]);
    }
}
