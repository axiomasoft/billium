<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Tests\Inbox\CustodyScenario;
use Billium\Platform\Tests\Inbox\InboxTestCase;
use Billium\Platform\Tests\Inbox\JournalHandler;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * ACC-CON-16: four processes deliver sequences 11..14 of one partition (applied 10) at the same moment; the partition
 * lock and the one-event-per-root rule apply them in source order exactly once, whatever the arrival order.
 */
#[Group('ACC-CON-16')]
#[Group('REQ-FAIL-13')]
final class FeedPartitionApplyTest extends InboxTestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_a_gap_inserted_after_partition_creation_is_reattached_and_drained(bool $redeliver): void
    {
        $paused = false;
        $this->owner()->listen(function (QueryExecuted $query) use (&$paused): void {
            if ($paused || ! str_contains($query->sql, 'SELECT * FROM public.billium_feed_partition')
                || ! str_contains($query->sql, "state <> 'retired'")) {
                return;
            }
            // B has fetched no live partition. Keep it here until A opens and commits sequence 1.
            $paused = true;
            self::assertSame(0, $this->rows('billium_feed_partition'));
            $results = ConcurrencyStand::run(CustodyScenario::class, 1, ['sequences' => [1]], 60);
            self::ok($results[0]);
            self::assertSame(1, $this->rows('billium_inbox_message', "status = 'applied'"));
        });
        $handler = new JournalHandler;
        $parked = $this->custody($this->envelope(2), $handler);
        self::assertTrue($paused, 'the race must pause immediately after the null live-partition read');
        self::assertSame(CustodyDisposition::ParkedGap, $parked->disposition);
        self::assertNull($this->inbox('evt-L1-2')['partition_id']);
        $id = self::integer($this->inbox('evt-part-1-1')['partition_id']);

        if ($redeliver) {
            $duplicate = $this->custody($this->envelope(2), $handler);
            self::assertSame(CustodyDisposition::Duplicate, $duplicate->disposition);
            self::assertSame($parked->inboxId, $duplicate->inboxId);
            self::assertSame($id, $duplicate->drainPartitionId);
            self::assertSame([2, 1], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence']]);
        }

        self::assertSame(CustodyDisposition::Applied, $this->drain($id, $handler)->disposition);
        self::assertSame(CustodyDisposition::Idle, $this->drain($id, $handler)->disposition);
        self::assertSame($id, $this->inbox('evt-L1-2')['partition_id']);
        self::assertSame('applied', $this->inbox('evt-L1-2')['status']);
        self::assertSame([2, 2, 'active'], [$this->partitionRow($id)['custody_sequence'], $this->partitionRow($id)['accounted_sequence'], $this->partitionRow($id)['state']]);
        self::assertSame(['evt-part-1-1', 'evt-L1-2'], $this->journal());
        self::assertSame(1, $handler->calls);
    }

    public function test_four_workers_apply_sequences_eleven_to_fourteen_in_order(): void
    {
        $id = $this->seedPartition(10);

        $results = ConcurrencyStand::run(CustodyScenario::class, 4, ['sequences' => [11, 12, 13, 14], 'sleeps' => [80, 80, 80, 80]], 60);

        $this->assertAppliedInOrder($id, $results);
    }

    public function test_reverse_arrival_parks_the_gap_and_still_applies_in_order(): void
    {
        $id = $this->seedPartition(10);

        // 14 arrives first, 11 last: 12..14 are parked in custody until 11 is accounted, then drained one root each.
        $results = ConcurrencyStand::run(CustodyScenario::class, 4, [
            'sequences' => [11, 12, 13, 14], 'delays' => [450, 300, 150, 0], 'sleeps' => [20, 20, 20, 20],
        ], 60);

        $this->assertAppliedInOrder($id, $results);
        $drained = array_sum(array_map(static fn (array $result): int => self::integer($result['ok']['drained'] ?? 0), $results));
        self::assertSame(3, $drained, 'sequences 12..14 were drained by new roots, not inline');
    }

    /** @param list<array{index: int, ok?: array<string, mixed>, error?: array{code: string}}> $results */
    private function assertAppliedInOrder(int $id, array $results): void
    {
        foreach ($results as $result) {
            self::ok($result);
        }
        $partition = $this->partitionRow($id);
        self::assertSame([14, 14, 'active'], [$partition['accounted_sequence'], $partition['custody_sequence'], $partition['state']]);
        self::assertSame(4, $this->rows('billium_inbox_message', "status = 'applied'"));
        self::assertSame(4, $this->rows('billium_inbox_message'));
        self::assertSame(['evt-part-1-11', 'evt-part-1-12', 'evt-part-1-13', 'evt-part-1-14'], $this->journal(), 'handler journal is in source order, once each');
    }
}
